<?php

declare(strict_types=1);

namespace Bitrix\AI\Integration\Intranet\AnnualSummary;

use Bitrix\Im\Model\MessageTable;
use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Intranet\Internal\Entity\AnnualSummary\Summary;
use Bitrix\Intranet\Public\Provider\AnnualSummary\SummaryProviderInterface;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Entity\EntityCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\Type\Date;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Requests a person made in a BitrixGPT chat, split by calendar season.
 *
 * A request is a message of the person in a chat of type BitrixGPT where that person takes
 * part: bot answers and system notifications are not the person's requests and carry another
 * author. A request counts even when the bot did not answer it.
 *
 * The collector keeps one number per metric name, so the seasons live under four names, and the
 * product asked for one more number next to them: the all time count of the person's requests,
 * which is not limited by the period the collector runs for.
 *
 * Three states stay distinguishable without a text payload in the storage:
 * - the person never used BitrixGPT: the value rows are not written at all, so a reader tells
 *   "no data" from a real zero by their absence (`findByIdAndUserId()` returns null);
 * - the person has the chat but no requests: the four season rows and the all time row carry zeros;
 * - the batch failed to calculate: only the *_error row is written with 1, the reason goes to the
 *   log, and the batch is not silently skipped.
 *
 * The flag is written by the failure path alone: a successful pass leaves the storage without it, so
 * the ordinary person has no row that says "calculated fine". ponytail: the storage merges rows and
 * never deletes, so a flag left by an earlier failed pass over the same people would stay - the
 * collector walks the portal once per year and moves the cursor past a failed batch, so there is
 * nothing to clear in the normal run; a pointwise delete in the collector is the upgrade path if the
 * run ever gains retries.
 */
class ChatRequestsProvider implements SummaryProviderInterface
{
	public const PROVIDER_ID = 'ai.chat_requests';
	public const SEASON_ID_PREFIX = self::PROVIDER_ID . '_';
	/** All time requests of the person, not limited by the period of the collector. */
	public const TOTAL_ID = self::PROVIDER_ID . '_total';
	/** Flag row: 1 means the batch failed to calculate, so no value rows exist for these people. */
	public const ERROR_ID = self::PROVIDER_ID . '_error';

	/**
	 * The batch stays at a hundred people: a failed batch leaves its people without values, and that
	 * fallout is accepted for this size (ADR §5). A cheaper query does not make the fallout smaller, so
	 * a larger batch needs the acceptance to be widened first - the provider of calls runs with five
	 * hundred under its own acceptance, and this one moves there only together with the same decision.
	 */
	public const USER_LIMIT = 100;

	private const LOGGER_ID = 'ai.annual_summary_provider';
	/**
	 * Chats per message query: the list of the batch is split into chunks of this size, so no query
	 * carries a literal list big enough for the optimizer to drop the range plan (the value the
	 * database guide of the repository asks for).
	 */
	private const CHAT_LIMIT = 500;

	private int $lastUserId = 0;
	private readonly LoggerInterface $logger;

	public function __construct(
		private readonly Date $from,
		private readonly Date $to,
		?LoggerInterface $logger = null,
	)
	{
		$this->logger = $logger ?? (new LoggerFactory())->createById(self::LOGGER_ID) ?? new NullLogger();
	}

	public function getId(): string
	{
		return self::PROVIDER_ID;
	}

	public function getUserIdLimit(): int
	{
		return self::USER_LIMIT;
	}

	public function getLastUserId(): int
	{
		return $this->lastUserId;
	}

	public function provide(array $userIds): EntityCollection
	{
		if ($userIds === [])
		{
			return new EntityCollection();
		}

		$userIds = array_map(static fn ($userId): int => (int)$userId, $userIds);
		// The cursor is seeded from the batch before the query runs. The collector keeps its own
		// progress and rebuilds the provider on every step, so on a failed batch the previous
		// position cannot be restored: without this the whole run would restart from the first
		// hundred people each time and never reach the rest of the portal.
		$this->lastUserId = max($userIds);

		try
		{
			$chatMap = $this->chatsOfUsers($userIds);
			$counts = [];

			// The messages of a chunk of the batch chats are read once: the season sums carry the period
			// inside the expression, and the all time number is the same pass. The all time card needs
			// the whole history of those chats anyway, so a second pass restricted to the period would
			// buy nothing but a doubled read.
			foreach (array_chunk($chatMap['chats'], self::CHAT_LIMIT) as $chatIds)
			{
				foreach ($this->countByUsers($userIds, $chatIds) as $userId => $values)
				{
					foreach ($values as $name => $value)
					{
						$counts[$userId][$name] = ($counts[$userId][$name] ?? 0) + $value;
					}
				}
			}

			return $this->buildCollection($userIds, $counts, $chatMap['users']);
		}
		catch (Throwable $throwable)
		{
			$this->logger->error('Failed to provide annual summary values.', [
				'providerId' => self::PROVIDER_ID,
				'from' => $this->from->format('Y-m-d'),
				'to' => $this->to->format('Y-m-d'),
				'userIds' => $userIds,
				'exception' => $throwable,
			]);

			return $this->buildErrorCollection($userIds);
		}
	}

	/**
	 * @param int[] $userIds
	 * @param array<int, array<string, int>> $counts
	 * @param array<int, true> $chatUsers
	 */
	private function buildCollection(array $userIds, array $counts, array $chatUsers): EntityCollection
	{
		$collection = new EntityCollection();
		foreach ($userIds as $userId)
		{
			if (!isset($chatUsers[$userId]))
			{
				// The person never used BitrixGPT: no value rows, and that absence is the no-data
				// state of the card, unlike a row with a honest zero.
				continue;
			}

			foreach (Season::getAll() as $season)
			{
				$collection->add(new Summary(
					$userId,
					self::SEASON_ID_PREFIX . $season,
					$counts[$userId][$season] ?? 0,
				));
			}

			$collection->add(new Summary($userId, self::TOTAL_ID, $counts[$userId][self::TOTAL_ID] ?? 0));
		}

		return $collection;
	}

	/**
	 * @param int[] $userIds
	 */
	private function buildErrorCollection(array $userIds): EntityCollection
	{
		$collection = new EntityCollection();
		foreach ($userIds as $userId)
		{
			$collection->add(new Summary($userId, self::ERROR_ID, 1));
		}

		return $collection;
	}

	/**
	 * One grouped query per chunk of the batch chats for all five numbers: the seasons are the period
	 * part of the same pass and the all time total is the rest of it, so the messages of those chats
	 * are read once. The chat of a message is joined to the relation of the person who wrote it, so a
	 * request counts only in a chat that person takes part in; the chat type is not checked again,
	 * because the ids already come from the BitrixGPT chats of the batch.
	 *
	 * @param int[] $userIds
	 * @param int[] $chatIds
	 * @return array<int, array<string, int>>
	 */
	protected function countByUsers(array $userIds, array $chatIds = []): array
	{
		if (!Loader::includeModule('im'))
		{
			return [];
		}

		if ($chatIds === [])
		{
			return [];
		}

		// The period sits in the season sums and not in the WHERE clause, because the total is not
		// limited by it: one pass projects all five numbers. The bounds come from the collector and not
		// from a request, so they are inlined into the expression.
		$from = $this->from->format('Y-m-d') . ' 00:00:00';
		$to = $this->to->format('Y-m-d') . ' 00:00:00';
		$seasonField = static function (array $months, string $alias) use ($from, $to): ExpressionField
		{
			return new ExpressionField(
				$alias,
				// EXTRACT is understood by both supported databases, unlike MONTH().
				'SUM(CASE WHEN %s >= \'' . $from . '\' AND %s < \'' . $to . '\''
				. ' AND EXTRACT(MONTH FROM %s) IN (' . implode(', ', $months) . ') THEN 1 ELSE 0 END)',
				['DATE_CREATE', 'DATE_CREATE', 'DATE_CREATE'],
			);
		};

		$result = MessageTable::query()
			->setSelect([
				'AUTHOR_ID',
				$seasonField([3, 4, 5], 'SPRING'),
				$seasonField([6, 7, 8], 'SUMMER'),
				$seasonField([9, 10, 11], 'AUTUMN'),
				$seasonField([12, 1, 2], 'WINTER'),
				new ExpressionField('TOTAL_REQUESTS', 'COUNT(%s)', ['ID']),
			])
			->whereIn('AUTHOR_ID', $userIds)
			->whereIn('CHAT_ID', $chatIds)
			->whereColumn('RELATION.USER_ID', 'AUTHOR_ID')
			->setGroup(['AUTHOR_ID'])
			->fetchAll()
		;

		$counts = [];
		foreach ($result as $row)
		{
			$counts[(int)$row['AUTHOR_ID']] = [
				Season::SPRING => (int)$row['SPRING'],
				Season::SUMMER => (int)$row['SUMMER'],
				Season::AUTUMN => (int)$row['AUTUMN'],
				Season::WINTER => (int)$row['WINTER'],
				self::TOTAL_ID => (int)$row['TOTAL_REQUESTS'],
			];
		}

		return $counts;
	}

	/**
	 * The chats of the batch and the people who have them: the chats are what the message queries are
	 * limited to, the people are the ones for whom the no-data state must not be used (they get honest
	 * zeros instead). The relations are read row by row and only the unique chat ids are kept, so a
	 * person with hundreds of copilot chats does not load a list the code then throws away.
	 *
	 * @param int[] $userIds
	 * @return array{chats: int[], users: array<int, true>}
	 */
	private function chatsOfUsers(array $userIds): array
	{
		if (!Loader::includeModule('im'))
		{
			return ['chats' => [], 'users' => []];
		}

		$result = RelationTable::query()
			->setSelect(['USER_ID', 'CHAT_ID'])
			->whereIn('USER_ID', $userIds)
			->where('CHAT.TYPE', Chat::IM_TYPE_COPILOT)
			->exec()
		;

		$users = [];
		$chats = [];
		while ($row = $result->fetch())
		{
			$users[(int)$row['USER_ID']] = true;
			$chats[(int)$row['CHAT_ID']] = true;
		}

		return ['chats' => array_keys($chats), 'users' => $users];
	}
}

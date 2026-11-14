<?php

declare(strict_types=1);

namespace Bitrix\Call\Integration\Intranet\AnnualSummary;

use Bitrix\Call\Integration\AI\SenseType;
use Bitrix\Call\Model\CallOutcomeTable;
use Bitrix\Call\Model\CallTable;
use Bitrix\Call\Model\CallUserTable;
use Bitrix\Intranet\Internal\Entity\AnnualSummary\Summary;
use Bitrix\Intranet\Public\Provider\AnnualSummary\SummaryProviderInterface;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Entity\EntityCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Type\Date;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

if (!Loader::includeModule('intranet'))
{
	return;
}

/**
 * Calls BitrixGPT parsed for a person: the annual summary card "how many calls BitrixGPT analysed
 * for them".
 *
 * A call counts for a person when that person takes part in it (b_call_user) and the call has an
 * overview outcome - the Follow-Up result BitrixGPT produces for a call. The call date is
 * b_call.START_DATE inside the half open period the collector passes.
 *
 * Three states stay distinguishable without a text payload in the storage:
 * - the person took part in no call of the period: the value row is not written at all, so a
 *   reader tells "no data" from a real zero by its absence (`findByIdAndUserId()` returns null);
 * - the person took part in calls, but none of them was parsed: the value row carries a zero;
 * - the batch failed to calculate: only the *_error row is written with 1 and the reason goes to
 *   the log, so the batch is not silently skipped.
 *
 * The flag is written by the failure path alone: a successful pass leaves the storage without it, so
 * the ordinary person has no row that says "calculated fine". ponytail: the storage merges rows and
 * never deletes, so a flag left by an earlier failed pass over the same people would stay - the
 * collector walks the portal once per year and moves the cursor past a failed batch, so there is
 * nothing to clear in the normal run; a pointwise delete in the collector is the upgrade path if the
 * run ever gains retries.
 */
class ParsedCallsProvider implements SummaryProviderInterface
{
	public const PROVIDER_ID = 'call.parsed_calls';
	/** Flag row: 1 means the batch failed to calculate, so no value row exists for these people. */
	public const ERROR_ID = self::PROVIDER_ID . '_error';

	/**
	 * The batch is five times the size of the other providers on purpose: the read of the calls of
	 * the period is the same for a batch of a hundred people and for a batch of five hundred
	 * (measured: 0,244 s and 0,255 s per batch on a volume of 300 000 calls, 900 000 participations
	 * and 90 000 outcomes), so the number of batches is what the yearly run pays for, and a larger
	 * batch divides that number. The price is the accepted fallout of a failed batch: the people of
	 * the batch stay without values and get the error flag.
	 */
	public const USER_LIMIT = 500;

	private const LOGGER_ID = 'call.annual_summary_provider';

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
		// The collector rebuilds the provider on every step and keeps its own progress, so the
		// cursor has to be known before the query: otherwise a failed batch would restart the
		// whole portal traversal.
		$this->lastUserId = max($userIds);

		try
		{
			$counts = $this->countByUsers($userIds);

			return $this->buildCollection($userIds, $counts);
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
	 * @param array<int, int> $counts
	 */
	private function buildCollection(array $userIds, array $counts): EntityCollection
	{
		$collection = new EntityCollection();
		foreach ($userIds as $userId)
		{
			// The row of a person is the group of the query: a group exists exactly when the person
			// took part in at least one call of the period.
			if (!isset($counts[$userId]))
			{
				// The person never took part in a call: no value row, and that absence is the
				// no-data state of the card, unlike a row with a honest zero.
				continue;
			}

			$collection->add(new Summary($userId, self::PROVIDER_ID, $counts[$userId]));
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
	 * One grouped query per batch for both numbers: how many calls of the period the person took
	 * part in (the honest zero state) and how many of them carry an overview outcome (the card).
	 *
	 * The two numbers used to come from two passes over the same rows - an aggregate with a semi
	 * join and a second one that only asked whether the person appears at all. Now the period is
	 * read once and the parsed ones are told from the rest by the left join, so a batch pays for a
	 * single scan.
	 *
	 * ponytail: the driver is still the call date, because b_call_user has the primary key
	 * (CALL_ID, USER_ID) and no index starting with USER_ID, and an index added to a shared table
	 * of every portal is not worth a once-a-year pass. Ceiling: the period is read once per batch
	 * instead of once per person, so the yearly run is O(batches), and the index (USER_ID, CALL_ID)
	 * - or reading the participation journal IX_USER_STATUS_TIME (USER_ID, STATUS_TIME, ID) - is
	 * the upgrade path if the panel gains a second consumer.
	 *
	 * @param int[] $userIds
	 * @return array<int, int>
	 */
	protected function countByUsers(array $userIds): array
	{
		$result = CallTable::query()
			->registerRuntimeField(new Reference(
				'PARTICIPATION',
				CallUserTable::class,
				Join::on('this.ID', 'ref.CALL_ID'),
				['join_type' => Join::TYPE_INNER],
			))
			// The outcome of another type (transcription, summary, insights) is not a parsed call for
			// the card, and the left join keeps the calls with no overview in the result: they are the
			// difference between the honest zero and the no-data state.
			->registerRuntimeField(new Reference(
				'OVERVIEW',
				CallOutcomeTable::class,
				Join::on('this.ID', 'ref.CALL_ID')->where('ref.TYPE', SenseType::OVERVIEW->value),
				['join_type' => Join::TYPE_LEFT],
			))
			->setSelect([
				'USER_ID' => 'PARTICIPATION.USER_ID',
				// One aggregate is enough for both numbers of the card: the groups of this query are
				// the people who took part in a call of the period, and this count is the parsed
				// calls among them, so the pairs "in the result with 0" and "not in the result at
				// all" are the honest zero and the no-data state.
				new ExpressionField(
					'PARSED_CALLS',
					'COUNT(DISTINCT CASE WHEN %s IS NOT NULL THEN %s END)',
					['OVERVIEW.CALL_ID', 'ID'],
				),
			])
			->whereIn('PARTICIPATION.USER_ID', $userIds)
			->where('START_DATE', '>=', $this->from)
			->where('START_DATE', '<', $this->to)
			->setGroup(['PARTICIPATION.USER_ID'])
			->exec()
		;

		$counts = [];
		while ($row = $result->fetch())
		{
			// COUNT(DISTINCT CASE ...) skips the NULLs, so a call with several overview rows is still
			// one parsed call.
			$counts[(int)$row['USER_ID']] = (int)$row['PARSED_CALLS'];
		}

		return $counts;
	}
}

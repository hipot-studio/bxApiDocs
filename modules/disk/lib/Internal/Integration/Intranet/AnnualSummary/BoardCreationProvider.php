<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Integration\Intranet\AnnualSummary;

use Bitrix\Disk\Internal\Repository\BoardCreationStatisticsRepository;
use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Intranet\Internal\Entity\AnnualSummary\Summary;
use Bitrix\Intranet\Public\Provider\AnnualSummary\SummaryProviderInterface;
use Bitrix\Main\Entity\EntityCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\Date;
use Psr\Log\LoggerInterface;
use Throwable;

// The class implements an interface of intranet, so without that module its autoload would be fatal.
// The other half of the pair is the class_exists() check in the event handler.
if (!Loader::includeModule('intranet'))
{
	return;
}

/**
 * Contribution of disk to the annual summary: the number of boards a user created within the period of
 * the event, read from the monthly counters. The period is half-open, [from, to), as the agent of
 * intranet defines it, and its bounds fall on the first days of months, as the counters are stored.
 *
 * A batch is held - an empty collection, the cursor left where it was - while the backfill of the
 * counters is not complete and when the read fails: a partial number saved once would stay in the
 * summary. The agent of intranet moves to the next provider as soon as a batch is shorter than the
 * limit, whatever the cursor says, so a held batch also becomes the limit: the agent then repeats the
 * very same batch on its next step instead of leaving the last users of the portal without the number.
 *
 * The agent asks the limit before it reads the batch and builds the provider anew on every step, so the
 * state of the backfill is read once per instance: until it is complete the agent is asked for one user
 * at a time, and a backfill completing within the step changes neither the limit nor the answer.
 */
final class BoardCreationProvider implements SummaryProviderInterface
{
	public const ID = 'disk.created_boards';
	public const USER_ID_LIMIT = 5000;

	private const LOGGER_ID = 'board_statistics';

	private int $lastUserId = 0;
	private ?int $userIdLimit = null;
	private ?bool $backfillCompleted = null;
	private readonly BoardCreationStatisticsRepository $repository;
	private readonly LoggerInterface $logger;

	public function __construct(
		private readonly Date $from,
		private readonly Date $to,
		?BoardCreationStatisticsRepository $repository = null,
		?LoggerInterface $logger = null,
	)
	{
		$this->repository = $repository ?? new BoardCreationStatisticsRepository();
		$this->logger = $logger ?? LoggerFactory::create(self::LOGGER_ID, feature: 'annual summary');
	}

	public function getId(): string
	{
		return self::ID;
	}

	public function getLastUserId(): int
	{
		return $this->lastUserId;
	}

	public function getUserIdLimit(): int
	{
		$this->userIdLimit ??= $this->isBackfillCompleted() ? self::USER_ID_LIMIT : 1;

		return $this->userIdLimit;
	}

	/**
	 * @param int[] $userIds
	 * @return EntityCollection<Summary>
	 */
	public function provide(array $userIds): EntityCollection
	{
		$collection = new EntityCollection();
		$ids = $this->normalizeUserIds($userIds);
		if ($ids === [] || !$this->isBackfillCompleted())
		{
			return $collection;
		}

		try
		{
			$totals = $this->repository->sumByUsers($ids, $this->from, $this->to);
		}
		catch (Throwable $exception)
		{
			$this->logger->error('Failed to provide the number of created boards for the annual summary.', [
				'providerId' => self::ID,
				'from' => $this->from->format('Y-m-d'),
				'to' => $this->to->format('Y-m-d'),
				'firstUserId' => min($ids),
				'lastUserId' => max($ids),
				'exception' => $exception,
			]);
			$this->holdBatch($userIds);

			return $collection;
		}

		foreach ($ids as $userId)
		{
			$collection->add(new Summary($userId, self::ID, (int)($totals[$userId] ?? 0)));
		}
		$this->lastUserId = max($ids);

		return $collection;
	}

	private function isBackfillCompleted(): bool
	{
		if ($this->backfillCompleted === null)
		{
			try
			{
				$this->backfillCompleted = (bool)$this->repository->getState()?->isCompleted();
			}
			catch (Throwable $exception)
			{
				$this->logger->error('Failed to read the board creation backfill state for the annual summary.', [
					'providerId' => self::ID,
					'from' => $this->from->format('Y-m-d'),
					'to' => $this->to->format('Y-m-d'),
					'exception' => $exception,
				]);
				$this->backfillCompleted = false;
			}
		}

		return $this->backfillCompleted;
	}

	/**
	 * The batch as the agent counts it, so the check "shorter than the limit" fails and the batch is
	 * asked again. The limit stays after a successful repeat: the moved cursor then yields the next
	 * batch, and an empty or shorter one finishes the provider in the usual way.
	 */
	private function holdBatch(array $userIds): void
	{
		$this->userIdLimit = max(1, min(self::USER_ID_LIMIT, count($userIds)));
	}

	/**
	 * @return list<int>
	 */
	private function normalizeUserIds(array $userIds): array
	{
		$ids = [];
		foreach ($userIds as $userId)
		{
			$userId = (int)$userId;
			if ($userId > 0)
			{
				$ids[$userId] = $userId;
			}
		}

		return array_values($ids);
	}
}

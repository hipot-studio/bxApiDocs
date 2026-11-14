<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Quota;

use Bitrix\Disk\Internal\Repository\Interface\UserQuotaRecalculationRepositoryInterface;
use Bitrix\Disk\Volume\Timer;

/**
 * Works the recalculation queue off: takes a chunk of the quota rows that owe a recalculation,
 * claims each of them, aggregates the used size over the source of truth and writes it back.
 *
 * The queue is the state of the rows themselves, so a storm of invalidations over one employee
 * collapses into a single row waiting - and a single run over it. What one run leaves behind is
 * taken by the next one on the interval of the agent: the depth of the queue is answered by the size
 * of the chunk and by that interval, since the agent mechanism cannot express a continuation.
 */
class UsageRecalculator
{
	/** Rows one run takes on; the rest waits for the next run of the agent. */
	public const CHUNK_SIZE = 50;

	/**
	 * For how long a row claimed by a run is left alone, seconds. Well past the limit of a single
	 * run, so the threshold is reached by a run that died rather than by one still working.
	 */
	public const RECLAIM_TTL = 1800;

	/**
	 * For how long a counter is trusted without a recalculation, seconds. This is what makes the run
	 * a reconciliation: a delta lost where there was nobody to report it would otherwise sit in the
	 * counter forever, since nothing marks such a row stale.
	 */
	public const VERIFY_TTL = 604800;

	public function __construct(
		private readonly UserQuotaRecalculationRepositoryInterface $repository,
		private readonly StorageUsageCounter $usageCounter,
	)
	{
	}

	/**
	 * Recalculates a chunk of the queue and returns how many rows it got through.
	 */
	public function runQueue(): int
	{
		$timer = new Timer();
		$timer->startTimer();

		$candidates = $this->repository->findCandidates(
			static::CHUNK_SIZE,
			static::RECLAIM_TTL,
			static::VERIFY_TTL,
		);

		$recalculated = 0;

		foreach ($candidates as $candidate)
		{
			$userId = $candidate->getUserId();

			if ($userId === null)
			{
				continue;
			}

			$claim = $this->repository->capture($candidate);

			if ($claim === null)
			{
				continue;
			}

			$this->repository->finishRecalculation(
				$claim,
				$this->usageCounter->aggregateVersionsSize($userId),
			);

			$recalculated++;

			$timer->checkTimeEnd();

			if ($timer->hasTimeLimitReached())
			{
				break;
			}
		}

		return $recalculated;
	}
}

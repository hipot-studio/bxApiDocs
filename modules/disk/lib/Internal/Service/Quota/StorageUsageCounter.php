<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Quota;

use Bitrix\Disk\Driver;
use Bitrix\Disk\Internal\Entity\UserQuota;
use Bitrix\Disk\Internal\Repository\Interface\UserQuotaRecalculationRepositoryInterface;
use Bitrix\Disk\Internal\Repository\Interface\UserQuotaRepositoryInterface;
use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Throwable;

/**
 * Used size of the personal storage of an employee: the stored counter, the deltas that move it and
 * the synchronous aggregation over the source of truth.
 *
 * An employee without a quota row is not counted at all - the counter is kept only for those a quota
 * is assigned to, so every operation over such an employee is a no-op.
 *
 * Accounting never costs the file operation it follows. The writing side - the delta, the mark and
 * the closing of a scope - carries its failures into the journal of the portal and leaves the row to
 * the recalculation queue instead of throwing them at the operation that has already written the
 * file. The side that judges a write answers as it always has: a question that cannot be answered
 * must not be taken for a yes.
 *
 * The service is stateful: it holds the quota rows read within the request and the scope of what an
 * operation has accumulated, so it is used as a singleton of the service locator.
 */
class StorageUsageCounter
{
	/** The journal the failures of the accounting are written into. */
	protected const LOG_FEATURE = 'disk personal quota accounting';

	/** @var array<int, UserQuota|null> */
	private array $rows = [];

	/** @var array<int, int> */
	private array $accumulatedDeltas = [];

	/** @var array<int, bool> Employees an open scope owes a mark for, by their id. */
	private array $deferredMarks = [];

	private int $accumulatorDepth = 0;

	public function __construct(
		private readonly UserQuotaRepositoryInterface $repository,
		private readonly UserQuotaRecalculationRepositoryInterface $recalculationRepository,
	)
	{
	}

	/**
	 * Returns the quota row of the employee - the last known used size together with the actuality
	 * status of it - or null when no quota is assigned to them.
	 */
	public function getUsage(int $userId): ?UserQuota
	{
		if (!array_key_exists($userId, $this->rows))
		{
			$this->rows[$userId] = $this->repository->findByUserId($userId);
		}

		return $this->rows[$userId];
	}

	/**
	 * Drops what the counter keeps about the employee: their row changed past the counter, so the
	 * next question about it is answered by the database.
	 */
	public function forget(int $userId): void
	{
		unset($this->rows[$userId]);
	}

	/**
	 * Moves the counter of the employee by $deltaBytes. While an accumulator scope is open the delta
	 * is summed in memory instead and reaches the database on the closing of the scope.
	 */
	public function applyDelta(int $userId, int $deltaBytes): void
	{
		try
		{
			if ($deltaBytes === 0 || $this->getUsage($userId) === null)
			{
				return;
			}

			if ($this->accumulatorDepth > 0)
			{
				$this->accumulatedDeltas[$userId] = ($this->accumulatedDeltas[$userId] ?? 0) + $deltaBytes;

				return;
			}

			$this->writeDelta($userId, $deltaBytes);
		}
		catch (Throwable $exception)
		{
			$this->handleAccountingFailure($userId, $deltaBytes, $exception);
		}
	}

	/**
	 * Aggregates the used size over the source of truth right now and writes it back by the very
	 * protocol the recalculation queue works the rows off with: the row is claimed first, and the
	 * claim is let go with the aggregated size. Returns the aggregated size, or null for an employee
	 * without a quota.
	 *
	 * The claim is what makes the write safe. A delta applied between the aggregation and the write
	 * lands on a row somebody holds, so it marks that row for one more pass instead of being buried
	 * by a size that never counted it. A row is claimed whatever state it was read in: one left
	 * behind by a count that broke is finished by the first write check that comes along, and a row
	 * a live run holds is protected by the claim itself - it only goes through if nobody has moved
	 * the row since it was read.
	 */
	public function computeNow(int $userId): ?int
	{
		$quota = $this->getUsage($userId);
		if ($quota === null)
		{
			return null;
		}

		$claim = $this->recalculationRepository->capture($quota);

		$size = $this->aggregateVersionsSize($userId);

		if ($claim !== null)
		{
			$this->recalculationRepository->finishRecalculation($claim, $size);
		}

		// The size is kept whether or not the row took it: a claim refused belongs to somebody else,
		// and a counter that forgot the size would answer the next question of the very same request
		// by one more aggregation over the whole personal storage.
		$this->rows[$userId] = (clone $quota)->setUsedSize($size);

		return $size;
	}

	/**
	 * Sums the sizes of the version rows of the personal storage subtree - the source of truth both
	 * this counter and the recalculation run answer by.
	 */
	public function aggregateVersionsSize(int $userId): int
	{
		$rootFolder = Driver::getInstance()->getStorageByUserId($userId)?->getRootObject();

		return $rootFolder === null ? 0 : (int)$rootFolder->countSizeOfVersions();
	}

	/**
	 * Puts the counter of the employee into the recalculation queue. While an accumulator scope is
	 * open the mark waits for the deltas of that scope and reaches the database right after them.
	 */
	public function invalidate(int $userId): void
	{
		try
		{
			if ($this->getUsage($userId) === null)
			{
				return;
			}

			if ($this->accumulatorDepth > 0)
			{
				$this->deferredMarks[$userId] = true;

				return;
			}

			$this->markForRecalculation($userId);
		}
		catch (Throwable $exception)
		{
			$this->handleAccountingFailure($userId, 0, $exception);
		}
	}

	/**
	 * Opens a scope where deltas are summed per employee instead of reaching the database one by one.
	 * The caller closes the scope even when the operation fails, otherwise what it summed is lost.
	 */
	public function openAccumulator(): void
	{
		$this->accumulatorDepth++;
	}

	/**
	 * Closes the scope opened by openAccumulator() and writes one update per employee, then the marks
	 * the scope owes. Scopes nest: both reach the database on the closing of the outermost one.
	 */
	public function flushAccumulator(): void
	{
		if ($this->accumulatorDepth === 0)
		{
			return;
		}

		$this->accumulatorDepth--;

		if ($this->accumulatorDepth > 0)
		{
			return;
		}

		$deltas = $this->accumulatedDeltas;
		$this->accumulatedDeltas = [];

		$markedEmployees = array_keys($this->deferredMarks);
		$this->deferredMarks = [];

		// One employee the accounting broke on does not cost the rest of the scope its writes: what
		// was summed for the others is theirs, and the marks are owed all the same.
		foreach ($deltas as $userId => $deltaBytes)
		{
			try
			{
				$this->writeDelta($userId, $deltaBytes);
			}
			catch (Throwable $exception)
			{
				$this->handleAccountingFailure($userId, $deltaBytes, $exception);
			}
		}

		// The marks go after the deltas and not before them: a row marked first and moved by a delta
		// afterwards can be recalculated in between, and the delta then lands on an aggregate that
		// already counts it - understating what the employee has spent until the drift is verified.
		foreach ($markedEmployees as $userId)
		{
			try
			{
				$this->markForRecalculation($userId);
			}
			catch (Throwable $exception)
			{
				$this->handleAccountingFailure($userId, 0, $exception);
			}
		}
	}

	/**
	 * What is done with an accounting that broke: the reason goes into the journal of the portal, the
	 * row is left to the recalculation queue as far as that is still possible, and what the counter
	 * kept about the employee is dropped - it stands for a write that may or may not have landed.
	 */
	private function handleAccountingFailure(int $userId, int $deltaBytes, Throwable $exception): void
	{
		LoggerFactory::create(feature: static::LOG_FEATURE)->error(
			'Used size of user {userId} was not moved by {deltaBytes} bytes: {exception}',
			[
				'userId' => $userId,
				'deltaBytes' => $deltaBytes,
				'exception' => $exception,
			],
		);

		try
		{
			$this->repository->markForRecalculation($userId);
		}
		catch (Throwable)
		{
			// Nothing is left to write the failure down with: the drift of the row is found by the
			// sweep that verifies every counter anyway.
		}

		unset($this->rows[$userId]);
	}

	private function writeDelta(int $userId, int $deltaBytes): void
	{
		if ($deltaBytes === 0)
		{
			return;
		}

		$this->repository->applyDelta($userId, $deltaBytes);
		unset($this->rows[$userId]);
	}

	private function markForRecalculation(int $userId): void
	{
		$this->repository->markForRecalculation($userId);
		unset($this->rows[$userId]);
	}
}

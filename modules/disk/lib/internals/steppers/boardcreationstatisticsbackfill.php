<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internals\Steppers;

use Bitrix\Disk\Internal\Dto\BoardStatistics\BoardCreationBackfillState;
use Bitrix\Disk\Internal\Dto\BoardStatistics\BoardCreationIncrement;
use Bitrix\Disk\Internal\Repository\BoardCreationStatisticsRepository;
use Bitrix\Disk\Internal\Service\BoardStatistics\BackfillBoundaryFinder;
use Bitrix\Disk\Internal\Service\BoardStatistics\BoardCreationTracker;
use Bitrix\Disk\Internals\BoardCreationBackfillTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\Update\Stepper;
use Throwable;

/**
 * Counts the boards that existed before the live tracking started: every run reads one portion of
 * b_disk_object by ascending id, up to the fixed border, and adds the boards of the portion to the
 * monthly counters together with moving the cursor - in one transaction under the lock of the state row.
 *
 * An insert that took an id below the border may still be uncommitted when the border is fixed, so the
 * search of the start waits until the border has settled by the clock of the database.
 */
class BoardCreationStatisticsBackfill extends Stepper
{
	public const OBJECTS_PER_PORTION = 500;
	public const BORDER_SETTLE_SECONDS = 300;

	protected static $moduleId = 'disk';

	private readonly Connection $connection;
	private readonly BoardCreationStatisticsRepository $repository;

	public function __construct(
		?Connection $connection = null,
		private readonly int $portionSize = self::OBJECTS_PER_PORTION,
		?BoardCreationStatisticsRepository $repository = null,
	)
	{
		$this->connection = $connection ?? Application::getConnection();
		$this->repository = $repository ?? new BoardCreationStatisticsRepository($this->connection);
	}

	/**
	 * @inheritDoc
	 */
	public function execute(array &$option): bool
	{
		try
		{
			$state = $this->processPortion();
		}
		catch (Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return self::CONTINUE_EXECUTION;
		}

		$option['count'] = max(0, $state->maxObjectId - ($state->startObjectId ?? $state->maxObjectId) + 1);
		$option['steps'] = min($option['count'], max(0, $state->nextObjectId - ($state->startObjectId ?? $state->nextObjectId)));

		return $state->isCompleted() ? self::FINISH_EXECUTION : self::CONTINUE_EXECUTION;
	}

	/**
	 * Runs one portion and answers the state after it. Everything the portion writes - the counters and
	 * the cursor - is committed at once or not at all.
	 */
	public function processPortion(): BoardCreationBackfillState
	{
		$this->repository->initializeState();

		$this->connection->startTransaction();
		try
		{
			$state = $this->repository->lockState()
				?? throw new \RuntimeException('The board creation backfill state disappeared.');
			if (!$state->isCompleted() && $state->nextObjectId !== 0)
			{
				$state = $this->countPortion($state);
			}
			elseif (!$state->isCompleted() && $this->isBorderSettled($state))
			{
				$state = $this->locateStart($state);
			}

			$this->connection->commitTransaction();
		}
		catch (Throwable $exception)
		{
			$this->rollback();

			throw $exception;
		}

		return $state;
	}

	private function isBorderSettled(BoardCreationBackfillState $state): bool
	{
		return
			$state->maxObjectFixedAt === null
			|| $this->repository->isBorderFixedAtLeastSecondsAgo(self::BORDER_SETTLE_SECONDS);
	}

	private function locateStart(BoardCreationBackfillState $state): BoardCreationBackfillState
	{
		$startObjectId = (new BackfillBoundaryFinder($this->repository))->findFirstObjectIdNotOlderThan(
			BoardCreationTracker::trackedSince(),
			$state->maxObjectId,
		);
		if ($startObjectId === null)
		{
			return $this->complete($state, null, $state->maxObjectId + 1);
		}

		return $this->store($state, $startObjectId, $startObjectId, BoardCreationBackfillTable::STATUS_IN_PROGRESS);
	}

	private function countPortion(BoardCreationBackfillState $state): BoardCreationBackfillState
	{
		$portion = $this->repository->fetchObjectPortion($state->nextObjectId, $state->maxObjectId, $this->portionSize);
		if ($portion === [])
		{
			return $this->complete($state, $state->startObjectId, $state->nextObjectId);
		}

		$increments = [];
		foreach ($portion as $object)
		{
			if (BoardCreationTracker::isTrackedBoard($object))
			{
				$increments[] = new BoardCreationIncrement(
					$object['CREATED_BY'],
					BoardCreationTracker::monthOf($object['CREATE_TIME']),
				);
			}
		}
		$this->repository->incrementMany($increments);

		$nextObjectId = $portion[array_key_last($portion)]['ID'] + 1;
		if (count($portion) < $this->portionSize || $nextObjectId > $state->maxObjectId)
		{
			return $this->complete($state, $state->startObjectId, $nextObjectId);
		}

		return $this->store($state, $state->startObjectId, $nextObjectId, BoardCreationBackfillTable::STATUS_IN_PROGRESS);
	}

	private function complete(BoardCreationBackfillState $state, ?int $startObjectId, int $nextObjectId): BoardCreationBackfillState
	{
		return $this->store($state, $startObjectId, $nextObjectId, BoardCreationBackfillTable::STATUS_COMPLETED);
	}

	private function store(
		BoardCreationBackfillState $state,
		?int $startObjectId,
		int $nextObjectId,
		string $status,
	): BoardCreationBackfillState
	{
		$this->repository->saveState($nextObjectId, $startObjectId, $status);

		return new BoardCreationBackfillState(
			$state->maxObjectId,
			$startObjectId,
			$nextObjectId,
			$status,
			$state->maxObjectFixedAt,
		);
	}

	/** Inside an outer transaction the connection rolls back to the savepoint and reports it as an exception. */
	private function rollback(): void
	{
		try
		{
			$this->connection->rollbackTransaction();
		}
		catch (TransactionException)
		{
		}
	}
}

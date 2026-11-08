<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\V2\Internal\Model\InteractionLogTable;
use Bitrix\Tasks\V2\Internal\Service\Ranking\EventType;
use Bitrix\Tasks\V2\Internal\Service\Ranking\RankingConfig;
use Bitrix\Tasks\V2\Internal\Service\Ranking\RelationKind;

class InteractionLogRepository implements InteractionLogRepositoryInterface
{
	public function appendBatch(
		int $userId,
		array $taskIds,
		EventType $eventType,
		?RelationKind $relationKind,
		DateTime $createdAt,
	): void
	{
		$rows = [];
		foreach ($taskIds as $taskId)
		{
			$rows[] = [
				'USER_ID' => $userId,
				'TASK_ID' => (int)$taskId,
				'EVENT_TYPE' => $eventType->value,
				'RELATION_KIND' => $relationKind?->value,
				'CREATED_AT' => $createdAt,
			];
		}

		if ($rows === [])
		{
			return;
		}

		InteractionLogTable::addMulti($rows, true);
	}

	public function hasRecentOpenForUser(int $userId, DateTime $threshold): bool
	{
		$recent = InteractionLogTable::query()
			->setSelect(['ID'])
			->where('USER_ID', $userId)
			->where('EVENT_TYPE', EventType::Open->value)
			->where('CREATED_AT', '>=', $threshold)
			->setLimit(1)
			->fetch()
		;

		return $recent !== false;
	}

	public function findTaskIdsWithRecentEvent(
		int $userId,
		array $taskIds,
		EventType $eventType,
		?RelationKind $relationKind,
		DateTime $threshold,
	): array
	{
		$taskIds = array_values(array_unique(array_filter(array_map('intval', $taskIds))));
		if ($taskIds === [])
		{
			return [];
		}

		$query = InteractionLogTable::query()
			->setSelect(['TASK_ID'])
			->where('USER_ID', $userId)
			->whereIn('TASK_ID', $taskIds)
			->where('EVENT_TYPE', $eventType->value)
			->where('CREATED_AT', '>=', $threshold)
			->setGroup(['TASK_ID'])
		;

		if ($relationKind !== null)
		{
			$query->where('RELATION_KIND', $relationKind->value);
		}

		$found = [];
		foreach ($query->fetchAll() as $row)
		{
			$found[] = (int)$row['TASK_ID'];
		}

		return $found;
	}

	public function findEventsForWindow(int $userId, DateTime $cutoff): iterable
	{
		$result = InteractionLogTable::query()
			->setSelect(['ID', 'TASK_ID', 'EVENT_TYPE', 'RELATION_KIND', 'CREATED_AT'])
			->where('USER_ID', $userId)
			->where('CREATED_AT', '>=', $cutoff)
			// CREATED_AT is second-precision: without ID the row set at the limit
			// boundary is up to the DBMS and the score drifts between identical reads.
			->setOrder(['CREATED_AT' => 'DESC', 'ID' => 'DESC'])
			->setLimit(RankingConfig::getEventsLimit())
			->exec()
		;

		while ($row = $result->fetch())
		{
			yield $row;
		}
	}

	public function purgeOlderThan(DateTime $cutoff): void
	{
		$batchSize = RankingConfig::getPurgeBatchSize();
		$batches = 0;

		// Batched delete over the CREATED_AT index. No cursor is needed between passes
		// or runs: deleted rows leave the range, so the next pass again meets the
		// oldest of what is left.
		do
		{
			$rows = InteractionLogTable::query()
				->setSelect(['ID'])
				->where('CREATED_AT', '<', $cutoff)
				->setOrder(['CREATED_AT' => 'ASC'])
				->setLimit($batchSize)
				->fetchAll()
			;

			if ($rows === [])
			{
				break;
			}

			$ids = array_map(static fn (array $row): int => (int)$row['ID'], $rows);
			InteractionLogTable::deleteByFilter(['@ID' => $ids]);
		}
		while (count($rows) === $batchSize && ++$batches < self::PURGE_MAX_BATCHES_PER_RUN);
	}

	public function trimUserToCap(int $userId, int $cap, int $batchSize, int $maxBatches): bool
	{
		// The (cap+1)-th freshest row is the newest one eligible for deletion, so a
		// drained trim leaves exactly $cap rows. ID makes the boundary exact inside a
		// second.
		$boundary = InteractionLogTable::query()
			->setSelect(['ID', 'CREATED_AT'])
			->where('USER_ID', $userId)
			->setOrder(['CREATED_AT' => 'DESC', 'ID' => 'DESC'])
			->setLimit(1)
			->setOffset($cap)
			->fetch()
		;

		if ($boundary === false)
		{
			return false;
		}

		$boundaryTime = $boundary['CREATED_AT'];
		$boundaryId = (int)$boundary['ID'];
		$batchSize = max(1, $batchSize);
		$maxBatches = max(1, $maxBatches);
		$batches = 0;
		$lastBatchFull = false;

		do
		{
			$rows = InteractionLogTable::query()
				->setSelect(['ID'])
				->where('USER_ID', $userId)
				->where($this->boundaryAndOlder($boundaryTime, $boundaryId))
				->setOrder(['CREATED_AT' => 'ASC', 'ID' => 'ASC'])
				->setLimit($batchSize)
				->fetchAll()
			;

			if ($rows === [])
			{
				$lastBatchFull = false;
				break;
			}

			$ids = array_map(static fn (array $row): int => (int)$row['ID'], $rows);
			InteractionLogTable::deleteByFilter(['@ID' => $ids]);

			$lastBatchFull = count($rows) === $batchSize;
			++$batches;
		}
		while ($lastBatchFull && $batches < $maxBatches);

		// Work remains only when the batch ceiling stopped a still-full run.
		return $lastBatchFull && $batches >= $maxBatches;
	}

	/**
	 * Keyset comparison against the boundary pair, boundary row included: rows
	 * sharing its second are cut by ID, otherwise they would outlive every run.
	 */
	private function boundaryAndOlder(DateTime $boundaryTime, int $boundaryId): ConditionTree
	{
		return Query::filter()
			->logic('or')
			->where('CREATED_AT', '<', $boundaryTime)
			->where(
				Query::filter()
					->where('CREATED_AT', $boundaryTime)
					->where('ID', '<=', $boundaryId)
			)
		;
	}
}

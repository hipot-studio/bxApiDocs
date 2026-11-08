<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\Internals\Counter\CommentsCountCache;
use Bitrix\Tasks\Internals\Counter\CounterDictionary;
use Bitrix\Tasks\Internals\Counter\CounterTable;
use Bitrix\Tasks\V2\Internal\Entity\CounterCollection;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\CounterMapper;

class CounterRepository implements CounterRepositoryInterface
{
	private const TASK_ID_CHUNK = 500;

	public function __construct(
		private readonly CounterMapper $mapper,
	) {
	}

	public function createFromCollection(CounterCollection $collection): void
	{
		if ($collection->isEmpty())
		{
			return;
		}

		CounterTable::addMulti($this->mapper->mapFromCollection($collection), true);

		foreach ($collection as $counter)
		{
			CommentsCountCache::drop((int)$counter->userId);
		}
	}

	public function deleteByUserAndTaskAndType(int $userId, int|array $taskId, string $type): void
	{
		if (is_int($taskId))
		{
			$taskId = [$taskId];
		}

		CounterTable::deleteByFilter([
			'=USER_ID' => $userId,
			'@TASK_ID' => $taskId,
			'=TYPE' => $type,
		]);

		CommentsCountCache::drop($userId);
	}

	public function getCommentsCountByUserAndTasks(int $userId, array $taskIds): array
	{
		Collection::normalizeArrayValuesByInt($taskIds, false);

		if (empty($taskIds))
		{
			return [];
		}

		$values = CommentsCountCache::get($userId, $taskIds);

		$missing = array_values(array_diff($taskIds, array_keys($values)));
		if (!empty($missing))
		{
			$fetched = $this->fetchCommentsCount($userId, $missing);
			CommentsCountCache::set($userId, $fetched);
			$values += $fetched;
		}

		return array_replace(array_fill_keys($taskIds, 0), $values);
	}

	/**
	 * @param int[] $taskIds
	 * @return array<int, int>
	 */
	private function fetchCommentsCount(int $userId, array $taskIds): array
	{
		$result = array_fill_keys($taskIds, 0);

		$types = array_merge(
			array_values(CounterDictionary::MAP_COMMENTS),
			array_values(CounterDictionary::MAP_MUTED_COMMENTS),
		);

		foreach (array_chunk($taskIds, self::TASK_ID_CHUNK) as $chunk)
		{
			$rows = CounterTable::query()
				->setSelect(['TASK_ID', 'VALUE'])
				->where('USER_ID', $userId)
				->whereIn('TASK_ID', $chunk)
				->whereIn('TYPE', $types)
				// Counter::getCommentsCount() keeps the last matching row, so read in the same order.
				->setOrder(['ID' => 'ASC'])
				->exec()
			;

			while ($row = $rows->fetch())
			{
				$result[(int)$row['TASK_ID']] = (int)$row['VALUE'];
			}
		}

		return $result;
	}
}

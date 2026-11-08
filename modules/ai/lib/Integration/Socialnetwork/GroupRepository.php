<?php declare(strict_types=1);

namespace Bitrix\AI\Integration\Socialnetwork;

use Bitrix\AI\BaseRepository;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Socialnetwork\WorkgroupTable;
use Closure;

class GroupRepository extends BaseRepository
{
	private const CHUNK_SIZE = 1000;

	/**
	 * @param int[] $groupIds
	 * @return list<int>
	 */
	public function getExistingIds(array $groupIds): array
	{
		return $this->filterIds(
			$groupIds,
			static fn(Query $query): Query => $query
				->where('ACTIVE', '=', true)
				->where('CLOSED', '=', false)
		);
	}

	/**
	 * @param int[] $groupIds
	 * @return list<int>
	 */
	public function getNotVisibleIds(array $groupIds): array
	{
		return $this->filterIds(
			$groupIds,
			static fn(Query $query): Query => $query->where('VISIBLE', '=', false)
		);
	}

	protected function createQuery(): Query
	{
		return WorkgroupTable::query();
	}

	/**
	 * @param int[] $ids
	 * @param Closure $predicate Adds the filtering conditions to the query
	 * @return list<int>
	 */
	private function filterIds(array $ids, Closure $predicate): array
	{
		// whereIn() on an empty array emits no condition at all, degrading the query into a
		// full table scan, so no query may ever be built for an empty id set. The guard below
		// makes that explicit; array_chunk() would not enter the loop for an empty input either.
		if (empty($ids))
		{
			return [];
		}

		$result = [];
		foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk)
		{
			$query = $this->createQuery()->setSelect(['ID']);
			$predicate($query);

			$rows = $query
				->whereIn('ID', $chunk)
				->fetchAll()
			;

			foreach ($rows as $row)
			{
				$result[] = (int)$row['ID'];
			}
		}

		return $result;
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Index;

use Bitrix\Disk\Internals\Index\ObjectExtendedIndexTable;

final class BoardIndexReader
{
	/**
	 * @param int[] $objectIds
	 * @return array<int, true>
	 */
	public function readyObjectIds(array $objectIds): array
	{
		$objectIds = self::normaliseIds($objectIds);
		if ($objectIds === [])
		{
			return [];
		}

		$rows = ObjectExtendedIndexTable::query()
			->setSelect(['OBJECT_ID'])
			->whereIn('OBJECT_ID', $objectIds)
			->where('STATUS', ObjectExtendedIndexTable::STATUS_BOARD_READY)
			->fetchAll()
		;

		$ready = [];
		foreach ($rows as $row)
		{
			$ready[(int)$row['OBJECT_ID']] = true;
		}

		return $ready;
	}

	public function readReadyText(int $objectId): ?string
	{
		$row = ObjectExtendedIndexTable::query()
			->setSelect(['SEARCH_INDEX'])
			->where('OBJECT_ID', $objectId)
			->where('STATUS', ObjectExtendedIndexTable::STATUS_BOARD_READY)
			->setLimit(1)
			->fetch()
		;
		if ($row === false)
		{
			return null;
		}

		return str_rot13((string)$row['SEARCH_INDEX']);
	}

	/**
	 * @param int[] $objectIds
	 * @return int[]
	 */
	private static function normaliseIds(array $objectIds): array
	{
		$normalised = [];
		foreach ($objectIds as $objectId)
		{
			$objectId = (int)$objectId;
			if ($objectId > 0)
			{
				$normalised[$objectId] = $objectId;
			}
		}

		return array_values($normalised);
	}
}

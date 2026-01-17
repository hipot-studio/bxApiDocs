<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\Internals\TaskTable;

class ParentTaskRepository implements ParentTaskRepositoryInterface
{
	public function getParentId(int $taskId): ?int
	{
		return $this->fetchParents([$taskId])[$taskId];
	}

	public function getParentIds(array $taskIds): array
	{
		return $this->fetchParents($taskIds);
	}

	private function fetchParents(array $taskIds): array
	{
		if (empty($taskIds))
		{
			return [];
		}

		$rows = TaskTable::query()
			->setSelect(['ID', 'PARENT_ID'])
			->whereIn('ID', $taskIds)
			->fetchAll()
		;

		$result = array_fill_keys($taskIds, null);

		foreach ($rows as $row)
		{
			$taskId = (int)($row['ID'] ?? 0);
			$parentId = (isset($row['PARENT_ID']) && (int)$row['PARENT_ID']) > 0 ? (int)$row['PARENT_ID'] : null;
			$result[$taskId] = $parentId;
		}

		return $result;
	}
}

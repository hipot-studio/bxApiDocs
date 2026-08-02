<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\V2\Internal\Entity\TaskCollection;

class InMemorySubTaskRepository implements SubTaskRepositoryInterface
{
	private readonly SubTaskRepository $subTaskRepository;

	private array $existenceCache = [];
	private array $subTaskIdsCache = [];
	private array $cache = [];

	public function __construct(SubTaskRepository $subTaskRepository)
	{
		$this->subTaskRepository = $subTaskRepository;
	}

	public function containsSubTasks(int $parentId): bool
	{
		if (!isset($this->existenceCache[$parentId]))
		{
			$this->existenceCache[$parentId] = $this->subTaskRepository->containsSubTasks($parentId);
		}

		return $this->existenceCache[$parentId];
	}

	public function getSubTaskIdsByParentIds(array $parentIds): array
	{
		$ids = [];

		foreach ($parentIds as $parentId)
		{
			if (!isset($this->subTaskIdsCache[$parentId]))
			{
				$ids[] = $parentId;
			}
		}

		if (!empty($ids))
		{
			$results = $this->subTaskRepository->getSubTaskIdsByParentIds($ids);
			foreach ($results as $parentId => $subTaskIds)
			{
				$this->subTaskIdsCache[$parentId] = $subTaskIds;
			}
		}

		$result = [];
		foreach ($parentIds as $parentId)
		{
			$result[$parentId] = $this->subTaskIdsCache[$parentId] ?? [];
		}

		return $result;
	}

	public function getByParentId(int $parentId, bool $withMembers = false): TaskCollection
	{
		if (!isset($this->cache[$parentId][$withMembers]))
		{
			$this->cache[$parentId][$withMembers] = $this->subTaskRepository->getByParentId($parentId, $withMembers);
		}

		return $this->cache[$parentId][$withMembers];
	}

	public function invalidate(int $taskId): void
	{
		unset($this->existenceCache[$taskId]);
		unset($this->cache[$taskId]);
	}
}

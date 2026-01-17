<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\V2\Internal\Entity\TaskCollection;

class InMemorySubTaskRepository implements SubTaskRepositoryInterface
{
	private readonly SubTaskRepository $subTaskRepository;

	private array $existenceCache = [];
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

	public function getByParentId(int $parentId): TaskCollection
	{
		if (!isset($this->cache[$parentId]))
		{
			$this->cache[$parentId] = $this->subTaskRepository->getByParentId($parentId);
		}

		return $this->cache[$parentId];
	}

	public function invalidate(int $taskId): void
	{
		unset($this->existenceCache[$taskId]);
		unset($this->cache[$taskId]);
	}
}

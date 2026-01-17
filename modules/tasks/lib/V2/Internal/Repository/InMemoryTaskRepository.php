<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\Internals\Registry\TaskRegistry;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Config\AddConfig;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Delete\Config\DeleteConfig;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;

class InMemoryTaskRepository implements TaskRepositoryInterface
{
	private TaskRepositoryInterface $taskRepository;

	private array $cache = [];
	private array $existenceCache = [];
	/** @var array<int, int> The cache of creator IDs keyed by task IDs. */
	private array $creatorIdsCache = [];

	public function __construct(TaskRepository $taskRepository)
	{
		$this->taskRepository = $taskRepository;
	}

	public function getById(int $id): ?Entity\Task
	{
		// Check if the task is already in the cache
		if (isset($this->cache[$id]))
		{
			return $this->cache[$id];
		}

		// Fetch the task from the underlying repository
		$task = $this->taskRepository->getById($id);

		// Cache the task if it exists
		if ($task !== null)
		{
			$this->cache[$id] = $task;
		}

		$this->existenceCache[$id] = $task !== null;

		return $task;
	}

	public function save(Entity\Task $entity): int
	{
		// Remove the task from the cache if it exists
		if (isset($this->cache[$entity->getId()]))
		{
			unset($this->cache[$entity->getId()]);
		}

		// Save the task using the underlying repository
		$taskId = $this->taskRepository->save($entity);

		$this->existenceCache[$taskId] = true;

		TaskRegistry::getInstance()->drop($taskId);

		return $taskId;
	}

	public function delete(int $id, bool $safe = true): void
	{
		// Delete the task using the underlying repository
		$this->taskRepository->delete($id, $safe);

		// Remove the task from the cache if it exists
		if (isset($this->cache[$id]))
		{
			unset($this->cache[$id]);
		}

		$this->existenceCache[$id] = false;

		TaskRegistry::getInstance()->drop($id);
	}

	public function isExists(int $id): bool
	{
		if ($id <= 0)
		{
			return false;
		}

		if (isset($this->existenceCache[$id]))
		{
			return $this->existenceCache[$id];
		}

		if (isset($this->cache[$id]))
		{
			$this->existenceCache[$id] = true;

			return true;
		}

		$registry = Container::getInstance()->getRegistry();
		if ($registry->isLoaded($id))
		{
			$this->existenceCache[$id] = true;

			return true;
		}

		$this->existenceCache[$id] = $this->taskRepository->isExists($id);

		return $this->existenceCache[$id];
	}

	public function invalidate(int $taskId): void
	{
		unset($this->cache[$taskId]);
	}

	public function updateLastActivityDate(int $taskId, int $activityTs): void
	{
		$this->taskRepository->updateLastActivityDate($taskId, $activityTs);
	}

	public function findCreatorIdsByTaskIds(array $taskIds): array
	{
		$nonCachedTaskIds = array_diff($taskIds, array_keys($this->creatorIdsCache));
		$this->creatorIdsCache = array_map('intval', $this->taskRepository->findCreatorIdsByTaskIds($nonCachedTaskIds))
			+ $this->creatorIdsCache;
		return array_intersect_key($this->creatorIdsCache, array_flip($taskIds));
	}

	public function findRecentTaskIdsWithChatIdsOrderedByActivityDate(int $userId, int $limit): array
	{
		return $this->taskRepository->findRecentTaskIdsWithChatIdsOrderedByActivityDate($userId, $limit);
	}

	public function countRecentTaskIdsWithChatIds(int $userId): int
	{
		return $this->taskRepository->countRecentTaskIdsWithChatIds($userId);
	}

	public function findTasksIdsWithChatIdsAndActiveCountersByUserIdAndGroupId(int $userId, ?int $groupId = null): array
	{
		return $this->taskRepository->findTasksIdsWithChatIdsAndActiveCountersByUserIdAndGroupId($userId, $groupId);
	}
}

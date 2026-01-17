<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\V2\Internal\Entity;

interface TaskRepositoryInterface
{
	public function getById(int $id): ?Entity\Task;

	public function save(Entity\Task $entity): int;

	public function delete(int $id, bool $safe = true): void;

	public function isExists(int $id): bool;

	public function invalidate(int $taskId);

	/**
	 * @param int $taskId The ID of the task to update the last activity date for.
	 * @param int $activityTs The timestamp of the last activity date.
	 * @return void 
	 */
	public function updateLastActivityDate(int $taskId, int $activityTs): void;

	/**
	 * @param int[] $taskIds The IDs of the tasks to find the creator IDs for.
	 * @return int[] The creator IDs for the tasks.
	 */
	public function findCreatorIdsByTaskIds(array $taskIds): array;

	/**
	 * @param int $userId The ID of the user to find the recent tasks for.
	 * @param int $limit The limit of the recent tasks to find.
	 * @return array{ID: int, TASKS_INTERNALS_TASK_CHAT_TASK_CHAT_ID: int|null}[] The recent tasks with chat IDs.
	 */
	public function findRecentTaskIdsWithChatIdsOrderedByActivityDate(int $userId, int $limit): array;

	/**
	 * @param int $userId The ID of the user to count the recent tasks for.
	 * @return int The number of recent tasks with chat IDs.
	 */
	public function countRecentTaskIdsWithChatIds(int $userId): int;

	/**
	 * @param int $userId The ID of the user to find the task IDs with active counters for.
	 * @return array{ID: int, TASKS_INTERNALS_TASK_CHAT_TASK_CHAT_ID: int|null}[] The task IDs with active counters for the user.
	 */
	public function findTasksIdsWithChatIdsAndActiveCountersByUserIdAndGroupId(int $userId, ?int $groupId = null): array;
}

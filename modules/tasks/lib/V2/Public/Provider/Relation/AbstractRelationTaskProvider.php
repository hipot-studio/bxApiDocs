<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Relation;

use Bitrix\Main\DB\Order;
use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Internals\Task\MetaStatus;
use Bitrix\Tasks\Internals\Task\Status;
use Bitrix\Tasks\Provider\Query\TaskQuery;
use Bitrix\Tasks\Provider\TaskList;
use Bitrix\Tasks\V2\Internal\Access\Service\TaskRightService;
use Bitrix\Tasks\V2\Internal\Entity\GroupCollection;
use Bitrix\Tasks\V2\Internal\Entity\TaskCollection;
use Bitrix\Tasks\V2\Internal\Entity\UserCollection;
use Bitrix\Tasks\V2\Internal\Repository\DeadlineChangeLogRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\GroupRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\RelationTaskMapper;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\TaskStatusMapper;
use Bitrix\Tasks\V2\Internal\Repository\SubTaskRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TaskParameterRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\UserRepositoryInterface;
use Bitrix\Tasks\V2\Public\Provider\Params\Relation\RelationTaskByIdsParams;
use Bitrix\Tasks\V2\Public\Provider\Params\Relation\RelationTaskParams;

abstract class AbstractRelationTaskProvider
{
	public function __construct(
		protected readonly TaskRightService $taskRightService,
		protected readonly TaskList $taskList,
		protected readonly UserRepositoryInterface $userRepository,
		protected readonly RelationTaskMapper $relationTaskMapper,
		protected readonly GroupRepositoryInterface $groupRepository,
		protected readonly SubTaskRepositoryInterface $subTaskRepository,
		protected readonly TaskParameterRepositoryInterface $taskParameterRepository,
		protected readonly TaskStatusMapper $taskStatusMapper,
		protected readonly DeadlineChangeLogRepositoryInterface $deadlineChangeLogRepository,
	)
	{

	}

	abstract protected function getFilter(RelationTaskParams $relationTaskParams): array;

	abstract protected function getFilterForIds(RelationTaskParams $relationTaskParams): array;

	abstract protected function getRelationRights(array $taskIds, int $rootId, int $userId): array;

	public function getTasks(RelationTaskParams $relationTaskParams): TaskCollection
	{
		if (!$this->checkRoot($relationTaskParams))
		{
			return new TaskCollection();
		}

		if (!$this->checkRootAccess($relationTaskParams))
		{
			return new TaskCollection();
		}

		$select = $relationTaskParams->getSelect() ?? $this->getDefaultSelect();

		$tasks = $this->fetchTasks(
			select: $select,
			filter: $this->getFilter($relationTaskParams),
			userId: $relationTaskParams->userId,
			offset: $relationTaskParams->getOffset(),
			limit: $relationTaskParams->getLimit(),
		);

		if (empty($tasks))
		{
			return new TaskCollection();
		}

		$taskIds = array_column($tasks, 'ID');
		Collection::normalizeArrayValuesByInt($taskIds, false);

		$users = $this->getUsers($tasks);
		$groups = $this->getGroups($tasks);
		$rights = $this->getRelationRights($taskIds, $relationTaskParams->taskId, $relationTaskParams->userId);

		$this->taskParameterRepository->preload($taskIds);

		$subTaskIds = [];
		if ($relationTaskParams->withSubTasks)
		{
			$subTaskIds = $this->subTaskRepository->getSubTaskIdsByParentIds($taskIds);
			$subTaskIds = $this->filterSubTaskIdsByReadAccess($subTaskIds, $relationTaskParams->userId);
		}

		$deadlineChangeCounts = $this->getDeadlineChangeCounts($relationTaskParams->userId, $taskIds);

		return $this->relationTaskMapper->mapToCollection(
			tasks: $tasks,
			users: $users,
			groups: $groups,
			rights: $rights,
			subTaskIds: $subTaskIds,
			deadlineChangeCounts: $deadlineChangeCounts,
		);
	}

	public function getTasksByIds(RelationTaskByIdsParams $params): TaskCollection
	{
		$ids = $params->taskIds;

		Collection::normalizeArrayValuesByInt($ids, false);

		if (empty($ids))
		{
			return new TaskCollection();
		}

		$tasks = $this->fetchTasks(
			select: $this->getDefaultSelect(),
			filter: $this->getIdsFilter($ids),
			userId: $params->userId,
		);

		if (empty($tasks))
		{
			return new TaskCollection();
		}

		$taskIds = array_column($tasks, 'ID');
		Collection::normalizeArrayValuesByInt($taskIds, false);

		$users = $this->getUsers($tasks);
		$groups = $this->getGroups($tasks);
		$rights = $this->getRelationRights($taskIds, 0, $params->userId);

		$this->taskParameterRepository->preload($taskIds);

		$subTaskIds = [];
		if ($params->withSubTasks)
		{
			$subTaskIds = $this->subTaskRepository->getSubTaskIdsByParentIds($taskIds);
			$subTaskIds = $this->filterSubTaskIdsByReadAccess($subTaskIds, $params->userId);
		}

		$deadlineChangeCounts = $this->getDeadlineChangeCounts($params->userId, $taskIds);

		return $this->relationTaskMapper->mapToCollection(
			tasks: $tasks,
			users: $users,
			groups: $groups,
			rights: $rights,
			subTaskIds: $subTaskIds,
			deadlineChangeCounts: $deadlineChangeCounts,
		);
	}

	public function getTaskIds(RelationTaskParams $relationTaskParams): array
	{
		if (!$this->checkRoot($relationTaskParams) || !$this->checkRootAccess($relationTaskParams))
		{
			return [];
		}

		$filter = $this->getFilterForIds($relationTaskParams);

		$query =
			(new TaskQuery($relationTaskParams->userId))
				->setSelect(['ID'])
				->setWhere($filter)
		;

		$tasks = $this->taskList->getList($query);

		$taskIds = array_column($tasks, 'ID');
		Collection::normalizeArrayValuesByInt($taskIds, false);

		return $taskIds;
	}

	public function getTaskIdsWithStatuses(RelationTaskParams $relationTaskParams): array
	{
		if (!$this->checkRoot($relationTaskParams) || !$this->checkRootAccess($relationTaskParams))
		{
			return ['ids' => [], 'statuses' => []];
		}

		$filter = $this->getFilterForIds($relationTaskParams);

		$query =
			(new TaskQuery($relationTaskParams->userId))
				->setSelect(['ID', 'STATUS'])
				->setWhere($filter)
		;

		$tasks = $this->taskList->getList($query);

		$taskIds = array_column($tasks, 'ID');
		Collection::normalizeArrayValuesByInt($taskIds, false);

		$taskIds = $this->filterByReadAccess($taskIds, $relationTaskParams->userId);

		$accessibleIds = array_flip($taskIds);

		$statuses = [];
		foreach ($tasks as $task)
		{
			$taskId = (int)$task['ID'];
			if (isset($accessibleIds[$taskId]))
			{
				$statuses[$taskId] = $this->taskStatusMapper->mapToEnum((int)($task['STATUS'] ?? 0));
			}
		}

		return ['ids' => $taskIds, 'statuses' => $statuses];
	}

	public function getTasksWithSubTaskIds(array $ids): TaskCollection
	{
		Collection::normalizeArrayValuesByInt($ids, false);

		if (empty($ids))
		{
			return new TaskCollection();
		}

		$subTaskIds = $this->subTaskRepository->getSubTaskIdsByParentIds($ids);

		return $this->relationTaskMapper->mapSubTaskIdsCollection(
			ids: $ids,
			subTaskIds: $subTaskIds,
		);
	}

	protected function getDefaultSelect(): array
	{
		return [
			'id',
			'title',
			'responsible',
			'deadline',
			'status',
			'group',
			'allowChangeDeadline',
			'activityDate',
			'changedDate',
		];
	}

	protected function translateSelect(array $select): array
	{
		$map = [
			'id' => 'ID',
			'title' => 'TITLE',
			'responsible' => 'RESPONSIBLE_ID',
			'deadline' => 'DEADLINE',
			'status' => 'STATUS',
			'group' => 'GROUP_ID',
			'allowChangeDeadline' => 'ALLOW_CHANGE_DEADLINE',
			'activityDate' => 'ACTIVITY_DATE',
			'changedDate' => 'CHANGED_DATE',
		];

		$result = [];
		foreach ($select as $field)
		{
			if (!is_string($field))
			{
				continue;
			}

			if (isset($map[$field]))
			{
				$result[] = $map[$field];
			}
		}

		return $result;
	}

	protected function fetchTasks(
		array $select,
		array $filter,
		int $userId,
		?int $offset = null,
		?int $limit = null,
	): array
	{
		$select = $this->translateSelect($select);

		if (empty($select))
		{
			return [];
		}

		$query =
			(new TaskQuery($userId))
				->setSelect($select)
				->setWhere($filter)
				->setOrder(['ACTIVITY_DATE' => Order::Desc->value])
		;

		if ($offset !== null)
		{
			$query->setOffset($offset);
		}

		if ($limit !== null)
		{
			$query->setLimit($limit);
		}

		return $this->taskList->getList($query);
	}

	protected function getUsers(array $tasks): UserCollection
	{
		$userIds = array_column($tasks, 'RESPONSIBLE_ID');

		Collection::normalizeArrayValuesByInt($userIds, false);

		if (empty($userIds))
		{
			return new UserCollection();
		}

		return $this->userRepository->getByIds($userIds);
	}

	protected function getGroups(array $tasks): GroupCollection
	{
		$groupIds = array_column($tasks, 'GROUP_ID');

		Collection::normalizeArrayValuesByInt($groupIds, false);

		if (empty($groupIds))
		{
			return new GroupCollection();
		}

		return $this->groupRepository->getByIds($groupIds);
	}

	protected function getIdsFilter(array $ids): array
	{
		return ['@ID' => $ids];
	}

	protected function checkRootAccess(RelationTaskParams $relationTaskParams): bool
	{
		if (!$relationTaskParams->checkRootAccess)
		{
			return true;
		}

		return $this->taskRightService->canView($relationTaskParams->userId, $relationTaskParams->taskId);
	}

	protected function checkRoot(RelationTaskParams $relationTaskParams): bool
	{
		return $relationTaskParams->taskId > 0;
	}

	protected function getDeadlineChangeCounts(int $userId, array $taskIds): array
	{
		$taskIdsWithLimit = array_filter(
			$taskIds,
			fn (int $taskId): bool => $this->taskParameterRepository->maxDeadlineChanges($taskId) > 0,
		);

		if (empty($taskIdsWithLimit))
		{
			return [];
		}

		return $this->deadlineChangeLogRepository->countUserChangesBatch($userId, $taskIdsWithLimit);
	}

	protected function filterByReadAccess(array $taskIds, int $userId): array
	{
		if (empty($taskIds))
		{
			return [];
		}

		$rights = $this->taskRightService->getTaskRightsBatch(
			userId: $userId,
			taskIds: $taskIds,
			rules: ['read' => ActionDictionary::ACTION_TASK_READ],
		);

		return array_values(
			array_filter(
				$taskIds,
				static fn (int $taskId): bool => !empty($rights[$taskId]['read']),
			),
		);
	}

	protected function filterSubTaskIdsByReadAccess(array $subTaskIds, int $userId): array
	{
		if (empty($subTaskIds))
		{
			return [];
		}

		$allSubTaskIds = [];
		foreach ($subTaskIds as $ids)
		{
			foreach ($ids as $id)
			{
				$allSubTaskIds[] = $id;
			}
		}

		$allSubTaskIds = array_unique($allSubTaskIds);

		if (empty($allSubTaskIds))
		{
			return [];
		}

		$accessibleIds = array_flip($this->filterByReadAccess($allSubTaskIds, $userId));

		$result = [];
		foreach ($subTaskIds as $parentId => $ids)
		{
			$filtered = array_values(
				array_filter(
					$ids,
					static fn (int $id): bool => isset($accessibleIds[$id]),
				),
			);

			if (!empty($filtered))
			{
				$result[$parentId] = $filtered;
			}
		}

		return $result;
	}

	protected function getActiveTasksFilter(): array
	{
		return [
			'STATUS' => [
				Status::NEW,
				Status::PENDING,
				Status::IN_PROGRESS,
				Status::SUPPOSEDLY_COMPLETED,
				MetaStatus::UNSEEN,
				MetaStatus::EXPIRED,
				MetaStatus::EXPIRED_SOON,
			],
		];
	}
}

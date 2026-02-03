<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Service;

use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Control\Exception\TaskNotExistsException;
use Bitrix\Tasks\V2\Internal\Access\AccessUserErrorTrait;
use Bitrix\Tasks\V2\Internal\Access\Factory\ControllerFactoryInterface;
use Bitrix\Tasks\V2\Internal\Access\Factory\Type;
use Bitrix\Tasks\V2\Internal\Entity\EntityInterface;
use Bitrix\Tasks\V2\Internal\Integration\Socialnetwork\Service\OperationAccessService;
use Bitrix\Tasks\V2\Internal\Repository\GroupRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TaskRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\UserRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\Task\MemberService;

class TaskAccessService
{
	use CanSaveTrait;
	use AccessUserErrorTrait;

	public function __construct(
		private readonly TaskRepositoryInterface $taskRepository,
		private readonly GroupRepositoryInterface $groupRepository,
		private readonly UserRepositoryInterface $userRepository,
		private readonly OperationAccessService $operationService,
		private readonly MemberService $memberService,
		private readonly ControllerFactoryInterface $controllerFactory,
	)
	{
	}

	public function canSave(int $userId, Entity\Task $task): bool
	{
		$result = $this->canSaveInternal(
			type: Type::Task,
			controllerFactory: $this->controllerFactory,
			saveAction: ActionDictionary::ACTION_TASK_SAVE,
			userId: $userId,
			entity: $task,
		);

		if (!$result)
		{
			$controller = $this->controllerFactory->create(Type::Task, $userId);

			$this->resolveUserError($controller);
		}

		return $result;
	}

	public function canRead(int $userId, int $taskId, array $params = []): bool
	{
		return $this->can($userId, ActionDictionary::ACTION_TASK_READ, $taskId, $params);
	}

	public function canUpdate(int $userId, int $taskId, array $params = []): bool
	{
		return $this->can($userId, ActionDictionary::ACTION_TASK_EDIT, $taskId, $params);
	}

	public function canDelete(int $userId, int $taskId, array $params = []): bool
	{
		return $this->can($userId, ActionDictionary::ACTION_TASK_REMOVE, $taskId, $params);
	}

	public function can(int $userId, string $action, ?int $taskId = null, array $params = []): bool
	{
		$controller = $this->controllerFactory->create(Type::Task, $userId);

		if ($controller === null)
		{
			return false;
		}

		$result = $controller->checkByItemId($action, $taskId, $params);

		if (!$result)
		{
			$this->resolveUserError($controller);
		}

		return $result;
	}

	// TODO: Move this code closer to where it's actually used. For chat integration.
	public function filterUsersWithAccess(int $taskId, array $userIds): array
	{
		$task = $this->taskRepository->getById($taskId);
		if ($task === null)
		{
			return [];
		}

		Collection::normalizeArrayValuesByInt($userIds);

		$memberIds = $task->getMemberIds();

		if ($memberIds === $userIds)
		{
			return $userIds;
		}

		$withAccess = $this->getAdminAccess($memberIds, $userIds);
		$checkAccess = array_diff($userIds, $withAccess);

		if (empty($checkAccess))
		{
			return array_intersect($withAccess, $userIds);
		}

		if ($task->group !== null)
		{
			$withAccess = $this->getGroupAccess($task, $withAccess, $userIds);
			$checkAccess = array_diff($userIds, $withAccess);

			if (empty($checkAccess))
			{
				return array_intersect($withAccess, $userIds);
			}
		}

		$finalAccess = $this->getDepartmentAccess($taskId, $withAccess, $checkAccess);

		return array_intersect($finalAccess, $userIds);
	}

	/**
	 * @throws TaskNotExistsException
	 */
	public function filterMemberUsers(int $taskId, array $userIds): array
	{
		Collection::normalizeArrayValuesByInt($userIds, false);

		$memberIds = $this->memberService->getMemberIds($taskId);

		Collection::normalizeArrayValuesByInt($memberIds, false);

		return [array_intersect($userIds, $memberIds),  array_diff($userIds, $memberIds)];
	}

	private function getAdminAccess(array $memberIds, array $userIds): array
	{
		$withAccess = $memberIds;
		$checkAccess = array_diff($userIds, $memberIds);

		if (empty($checkAccess))
		{
			return $withAccess;
		}

		$accessViaAdminRights = $this->userRepository->getAdmins()->getIds();

		return array_merge($withAccess, $accessViaAdminRights);
	}

	private function getGroupAccess($task, array $withAccess, array $userIds): array
	{
		$members = $this->groupRepository->getMembers($task->group->id);

		$members = array_map(
			static fn (Entity\User $user): array => [$user->id => $user->role],
			iterator_to_array($members)
		);

		//todo: simplify, merge queries with filterUsersWithAccess
		$accessViaNonMemberRights = [];
		foreach ($userIds as $userId)
		{
			if ($this->operationService->canViewAllTasks($userId, $task->group->id))
			{
				$accessViaNonMemberRights[] = $userId;
			}
		}

		$accessViaGroup = $this->operationService->filterUsersWithAccess(
			$task->group->id,
			$members,
			SONET_ENTITY_GROUP,
			'tasks',
			'view_all'
		);

		return array_merge($withAccess, $accessViaGroup, $accessViaNonMemberRights);
	}

	private function getDepartmentAccess(int $taskId, array $withAccess, array $checkAccess): array
	{
		$accessViaDepartment = [];
		foreach ($checkAccess as $userId)
		{
			$controller = $this->controllerFactory->create(Type::Task, $userId);
			if ($controller === null)
			{
				return $withAccess;
			}

			if ($controller->checkByItemId(ActionDictionary::ACTION_TASK_DEPARTMENT, $taskId))
			{
				$accessViaDepartment[] = $userId;
			}
		}

		return array_merge($withAccess, $accessViaDepartment);
	}

	private function getEntityById(int $id): ?EntityInterface
	{
		return $this->taskRepository->getById($id);
	}
}

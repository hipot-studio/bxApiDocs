<?php

namespace Bitrix\Tasks\Access;

use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\Internals\Registry\FeaturePermRegistry;
use Bitrix\Tasks\Access\Model\TaskModel;
use Bitrix\Tasks\Access\Model\UserModel;
use Bitrix\Tasks\Internals\Registry\TagRegistry;
use Bitrix\Tasks\Internals\Registry\TaskRegistry;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Integration\HumanResources\Facade\UserDepartmentsInMemoryFacade;

class AccessCacheLoader
{
	public function preload(int $userId, array $taskIds): void
	{
		$registry = $this->getTaskRegistry();

		$registry->load($taskIds, true);

		if (!Loader::includeModule('socialnetwork'))
		{
			return;
		}

		$groupIds = [];
		foreach ($taskIds as $id)
		{
			$task = $registry->get($id);
			if (
				!$task
				|| !$task['GROUP_ID']
			)
			{
				continue;
			}
			$groupIds[] = (int) $task['GROUP_ID'];
		}

		if (empty($groupIds))
		{
			return;
		}

		$sonetRegistry = FeaturePermRegistry::getInstance();
		$sonetRegistry->load($groupIds, 'tasks', 'edit_tasks', $userId);
		$sonetRegistry->load($groupIds, 'tasks', 'create_tasks', $userId);
		$sonetRegistry->load($groupIds, 'tasks', 'delete_tasks', $userId);
		$sonetRegistry->load($groupIds, 'tasks', 'view_all', $userId);
	}

	public function preloadForReadAccess(int $userId, array $taskIds): void
	{
		$this->preload($userId, $taskIds);

		if (empty($taskIds))
		{
			return;
		}

		$user = $this->getUserModel($userId);
		if ($user->isAdmin())
		{
			return;
		}

		$registry = $this->getTaskRegistry();
		$userIds = [];
		$subordinateIds = null;
		$isSocialNetworkAvailable = Loader::includeModule('socialnetwork');
		foreach ($taskIds as $taskId)
		{
			$taskId = (int) $taskId;
			$taskData = $registry->get($taskId);
			if (!$taskData)
			{
				continue;
			}

			$members = [];
			foreach ($taskData['MEMBER_LIST'] ?? [] as $member)
			{
				$memberId = (int) $member['USER_ID'];
				if ($memberId <= 0)
				{
					continue;
				}

				$members[$member['TYPE']][] = $memberId;
			}

			$task = TaskModel::createFromId($taskId)->preloadMembers($members);
			if ($task->isDeleted() || $task->isMember($userId))
			{
				continue;
			}

			$groupId = $task->getGroupId();
			if (
				$groupId > 0
				&& $isSocialNetworkAvailable
				&& FeaturePermRegistry::getInstance()->get($groupId, 'tasks', 'view_all', $userId)
			)
			{
				continue;
			}

			$memberIds = [];
			foreach ($task->getMembers() as $roleMemberIds)
			{
				foreach ($roleMemberIds as $memberId)
				{
					$memberIds[$memberId] = $memberId;
				}
			}

			$subordinateIds ??= array_flip($user->getAllSubordinates());
			if (!empty(array_intersect_key($memberIds, $subordinateIds)))
			{
				continue;
			}

			if ($userId > 0)
			{
				$userIds[$userId] = $userId;
			}
			$userIds += $memberIds;
		}

		if (!empty($userIds))
		{
			$this->getUserDepartmentsInMemoryFacade()->getByUsersIds(array_values($userIds));
		}
	}

	protected function getTaskRegistry(): TaskRegistry
	{
		return TaskRegistry::getInstance();
	}

	protected function getUserModel(int $userId): UserModel
	{
		return UserModel::createFromId($userId);
	}

	protected function getUserDepartmentsInMemoryFacade(): UserDepartmentsInMemoryFacade
	{
		return Container::getInstance()->getUserDepartmentsInMemoryFacade();
	}

	public function isLoaded(int $taskId): bool
	{
		return TaskRegistry::getInstance()->isLoaded($taskId);
	}

	public function isLoadedAndExists(int $taskId): bool
	{
		return TaskRegistry::getInstance()->isLoadedAndExists($taskId);
	}

	public function preloadTags(array $tagsIds): void
	{
		$registry = TagRegistry::getInstance();
		$registry->load($tagsIds);
	}

}

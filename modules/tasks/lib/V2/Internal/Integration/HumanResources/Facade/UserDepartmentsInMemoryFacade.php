<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\HumanResources\Facade;

use Bitrix\HumanResources\Item\NodeMember;
use Bitrix\HumanResources\Public\Service\Container;
use Bitrix\Main\Loader;

class UserDepartmentsInMemoryFacade
{
	private array $userToDepartmentsCache = [];

	/**
	 * @param int $userId
	 * @return int[]
	 */
	public function getByUserId(int $userId): array
	{
		if (!Loader::includeModule('humanresources'))
		{
			return [];
		}

		if (!isset($this->userToDepartmentsCache[$userId]))
		{
			$this->loadDepartmentIdsFromStorage([$userId]);
		}

		return $this->getDepartmentIdsFromCache([$userId]);
	}

	/**
	 * @param int[] $userIds
	 * @return int[]
	 */
	public function getByUsersIds(array $userIds): array
	{
		if (empty($userIds))
		{
			return [];
		}

		if (!Loader::includeModule('humanresources'))
		{
			return [];
		}

		$userIds = array_values(array_unique($userIds));

		$missingUserIds = $this->getUserIdsWithoutCache($userIds);

		if (!empty($missingUserIds))
		{
			$this->loadDepartmentIdsFromStorage($missingUserIds);
		}

		return $this->getDepartmentIdsFromCache($userIds);
	}

	/**
	 * @param int[] $userIds
	 * @return int[]
	 */
	private function getUserIdsWithoutCache(array $userIds): array
	{
		$result = [];
		foreach ($userIds as $userId)
		{
			if (isset($this->userToDepartmentsCache[$userId]))
			{
				continue;
			}

			$result[] = $userId;
		}

		return $result;
	}

	/**
	 * @param int[] $userIds
	 */
	private function loadDepartmentIdsFromStorage(array $userIds): void
	{
		$userToDepartments = [];

		$collections = Container::getNodeMemberService()->findAllByEntityIds(entityIds: $userIds);

		/**	@var NodeMember $item */
		foreach ($collections as $item)
		{
			$userId = $item->entityId;
			$departmentId = $item->nodeId;

			$userToDepartments[$userId][] = $departmentId;
		}

		foreach ($userIds as $userId)
		{
			$departments = array_key_exists($userId, $userToDepartments)
				? $userToDepartments[$userId]
				: [];

			$this->userToDepartmentsCache[$userId] = array_values(array_unique($departments));
		}
	}

	/**
	 * @param int[] $userIds
	 * @return int[]
	 */
	private function getDepartmentIdsFromCache(array $userIds): array
	{
		$result = [];

		foreach ($userIds as $userId)
		{
			if (!isset($this->userToDepartmentsCache[$userId]))
			{
				continue;
			}

			$result[] = $this->userToDepartmentsCache[$userId];
		}

		if (empty($result))
		{
			return $result;
		}

		return array_values(
			array_unique(
				array_merge(...$result),
			),
		);
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Humanresources;

use Bitrix\HumanResources\Contract\Repository\NodeRepository;
use Bitrix\HumanResources\Item\Collection\NodeCollection;
use Bitrix\HumanResources\Item\Role;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\NodeMemberRole;
use Bitrix\HumanResources\Util\NodeMemberCounterHelper;
use Bitrix\Intranet\Internal\Entity\User\Profile\BaseInfo;
use Bitrix\Intranet\Internal\Entity\User\Profile\SubordinateInfo;
use Bitrix\Intranet\Repository\UserRepository;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Data\ManagedCache;
use Bitrix\Main\Loader;

class SubordinateService
{
	private bool $isAvailable;
	private ?NodeRepository $nodeRepository = null;
	private ?Role $role = null;
	private NodeMemberCounterHelper $counterHelper;
	private ManagedCache $cache;
	private UserRepository $userRepository;
	private SubordinateRepository $repository;

	public function __construct()
	{
		$this->isAvailable = Loader::includeModule('humanresources');

		if ($this->isAvailable)
		{
			$this->nodeRepository = Container::getNodeRepository();
			$this->role = Container::getRoleRepository()->findByXmlId(NodeMemberRole::Head->value);
			$this->counterHelper = new NodeMemberCounterHelper();
			$this->cache = Application::getInstance()->getManagedCache();
			$this->userRepository = new UserRepository();
			$this->repository = new SubordinateRepository();

			$this->isAvailable = $this->role !== null && $this->role->id > 0;
		}
	}

	public function getDepartments(int $userId): ?array
	{
		if (!$this->isAvailable)
		{
			return null;
		}

		$result = [];
		foreach ($this->getAllDepartments($userId, $this->role->id) as $department)
		{
			$result[$department->id] = [
				'id' => $department->id,
				'headUserId' => $userId,
				'name' => $department->name,
			];
		}

		return array_values($result);
	}

	public function getPage(int $userId, int $departmentId, int $lastUserId = 0, int $limit = 20): ?array
	{
		$selectedDepartment = $this->getSelectedDepartment($userId, $departmentId);

		if ($selectedDepartment === null)
		{
			return null;
		}

		$userIds = $this->repository->getSubordinateIds($userId, $departmentId, $lastUserId, $limit);
		$hasMore = count($userIds) > $limit;

		if ($hasMore)
		{
			array_pop($userIds);
		}

		$subordinateUsers = $this->createSubordinateUsers($userIds);
		$nextCursor = null;

		if ($hasMore && $subordinateUsers !== [])
		{
			$lastUser = $subordinateUsers[array_key_last($subordinateUsers)];
			$nextCursor = $lastUser->getId();
		}

		return [
			'id' => $selectedDepartment['id'],
			'headUserId' => $selectedDepartment['headUserId'],
			'name' => $selectedDepartment['name'],
			'subordinateUsers' => $subordinateUsers,
			'hasMore' => $hasMore,
			'nextCursor' => $nextCursor,
		];
	}

	public function getFirst(int $userId): ?BaseInfo
	{
		$departments = $this->getDepartments($userId);
		if ($departments === null)
		{
			return null;
		}

		foreach ($departments as $department)
		{
			$userIds = $this->repository->getSubordinateIds($userId, $department['id'], 0, 1);
			$subUserId = $userIds[0] ?? null;
			if ($subUserId === null)
			{
				continue;
			}

			$user = $this->userRepository->findSubordinateUsersByIds([$subUserId])->first();
			if ($user !== null)
			{
				return SubordinateInfo::createByUserEntity($user);
			}
		}

		return null;
	}

	public function getCount(int $userId): int
	{
		if (!$this->isAvailable)
		{
			return 0;
		}

		$departments = $this->getAllDepartments($userId, $this->role->id);
		$count = 0;

		foreach ($departments as $department)
		{
			$count += $this->counterHelper->countByNodeId($department->id) - 1;
		}

		return $count;
	}

	private function getSelectedDepartment(int $userId, int $departmentId): ?array
	{
		$departments = $this->getDepartments($userId);
		if ($departments === null)
		{
			return null;
		}

		foreach ($departments as $department)
		{
			if ($department['id'] === $departmentId)
			{
				return $department;
			}
		}

		throw new ArgumentException('Department is not available to this user', 'departmentId');
	}

	private function createSubordinateUsers(array $userIds): array
	{
		$users = $this->userRepository->findSubordinateUsersByIds($userIds);
		$usersById = [];

		foreach ($users as $user)
		{
			$usersById[$user->getId()] = $user;
		}

		$subordinateUsers = [];

		foreach ($userIds as $id)
		{
			if (isset($usersById[$id]))
			{
				$subordinateUsers[] = SubordinateInfo::createByUserEntity($usersById[$id]);
			}
		}

		return $subordinateUsers;
	}

	private function getAllDepartments(int $userId, int $roleId): NodeCollection
	{
		$cacheId = 'hr_subordinates_' . $userId . '_' . $roleId;

		if ($this->cache->read(86400, $cacheId, 'intranet_subordinate_repository'))
		{
			$departments = $this->cache->get($cacheId);
		}
		else
		{
			$departments = $this->nodeRepository->findAllByUserIdAndRoleId($userId, $roleId);

			$this->cache->set($cacheId, $departments);
		}

		return $departments;
	}
}

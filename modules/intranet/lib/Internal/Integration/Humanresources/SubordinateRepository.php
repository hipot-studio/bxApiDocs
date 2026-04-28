<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Humanresources;

use Bitrix\HumanResources\Contract\Repository\NodeRepository;
use Bitrix\HumanResources\Contract\Service\NodeMemberService;
use Bitrix\HumanResources\Item\Collection\NodeCollection;
use Bitrix\HumanResources\Item\NodeMember;
use Bitrix\HumanResources\Item\Role;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Util\NodeMemberCounterHelper;
use Bitrix\Intranet\Internal\Entity\User\Department;
use Bitrix\Intranet\Internal\Entity\User\Profile\BaseInfo;
use Bitrix\Intranet\Internal\Entity\User\Profile\BaseInfoCollection;
use Bitrix\Intranet\Repository\UserRepository;
use Bitrix\Main\Application;
use Bitrix\Main\Data\ManagedCache;
use Bitrix\Main\Entity\EntityCollection;
use Bitrix\Main\Loader;

class SubordinateRepository
{
	private bool $isAvailable;
	private ?NodeRepository $nodeRepository = null;
	private ?NodeMemberService $memberService = null;
	private ?Role $role = null;
	private NodeMemberCounterHelper $counterHelper;
	private ManagedCache $cache;
	private UserRepository $userRepository;

	public function __construct()
	{
		$this->isAvailable = Loader::includeModule('humanresources');

		if ($this->isAvailable)
		{
			$this->nodeRepository = Container::getNodeRepository();
			$this->role = Container::getRoleRepository()->findByXmlId(NodeMember::DEFAULT_ROLE_XML_ID['HEAD']);
			$this->memberService = Container::getNodeMemberService();
			$this->counterHelper = new NodeMemberCounterHelper();
			$this->cache = Application::getInstance()->getManagedCache();
			$this->userRepository = new UserRepository();

			$this->isAvailable = $this->role !== null && $this->role->id > 0;
		}
	}

	public function getFirst(int $userId): ?BaseInfo
	{
		if (!$this->isAvailable)
		{
			return null;
		}

		$department = $this->getAllDepartments($userId, $this->role->id)->getFirst();
		if (!$department)
		{
			return null;
		}

		$subUserId =
			$this
				->memberService
				->getPagedEmployees($department->id, false, 1, 2)
				->getFirst()
				?->entityId
		;

		if ($subUserId === null)
		{
			return null;
		}

		$userCollection = $this->userRepository->findUsersByIds([$subUserId]);
		$user = $userCollection->first();

		return $user ? BaseInfo::createByUserEntity($user) : null;
	}

	public function getAll(int $userId): ?EntityCollection
	{
		if (!$this->isAvailable)
		{
			return null;
		}

		$result = new EntityCollection();
		$departments = $this->getAllDepartments($userId, $this->role->id);

		foreach ($departments as $dept)
		{
			$userIds = [];

			foreach ($this->memberService->getAllEmployees($dept->id) as $member)
			{
				if ($member->entityId !== $userId)
				{
					$userIds[] = $member->entityId;
				}
			}

			$result->add(
				new Department(
					$dept->id,
					$userId,
					$dept->name,
					BaseInfoCollection::createByUserCollection($this->userRepository->findUsersByIds(array_unique($userIds))),
				)
			);
		}

		return $result;
	}

	public function getCount(int $userId): int
	{
		if (!$this->isAvailable)
		{
			return 0;
		}

		$departments = $this->getAllDepartments($userId, $this->role->id);
		$count = 0;

		foreach ($departments as $dept)
		{
			$count += $this->counterHelper->countByNodeId($dept->id) - 1;
		}

		return $count;
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
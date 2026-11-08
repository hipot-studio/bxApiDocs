<?php

namespace Bitrix\Intranet\Integration\HumanResources;

use Bitrix\HumanResources;
use Bitrix\HumanResources\Item\Collection\NodeMemberCollection;
use Bitrix\HumanResources\Item\NodeMember;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\Intranet\Entity\Collection\DepartmentCollection;
use Bitrix\Intranet\Entity\Collection\UserCollection;
use Bitrix\Intranet\Entity\User;
use Bitrix\Main;

final class DepartmentAssigner
{
	private bool $available;

	public function __construct(
		protected DepartmentCollection $departmentCollection,
	)
	{
		$this->available = Main\Loader::includeModule('humanresources');
	}

	public function assignUsers(
		UserCollection $userCollection,
	): void
	{
		if (!$this->available || $this->departmentCollection->empty() || $userCollection->empty())
		{
			return;
		}

		$this->assignUsersWithExistingMemberMap(
			$userCollection,
			$this->getExistingMemberMap($userCollection->getIds()),
		);
	}

	public function assignUser(
		User $user,
	): void
	{
		if (!$this->available || $this->departmentCollection->empty())
		{
			return;
		}

		$this->assignUserWithExistingMemberMap(
			$user,
			$this->getExistingMemberMap([$user->getId()]),
		);
	}

	public function reassignUser(
		User $user,
	): void
	{
		$currentNodeMemberCollection = Container::getNodeMemberRepository()->findAllByEntityIdAndEntityTypeAndNodeType(
			entityId: $user->getId(),
			entityType: MemberEntityType::USER,
			nodeType: NodeEntityType::DEPARTMENT,
		);
		$existingMemberMap = $this->buildExistingMemberMap($currentNodeMemberCollection);
		$newDepartmentsIds = $this->departmentCollection->map(
			fn (\Bitrix\Intranet\Entity\Department $department) => $department->getId(),
		);
		$nodeMemberCollectionToRemove = $currentNodeMemberCollection->filter(
			fn(NodeMember $nodeMember) => !in_array($nodeMember->nodeId, $newDepartmentsIds),
		);

		if (!$nodeMemberCollectionToRemove->empty())
		{
			HumanResources\Service\Container::getNodeMemberRepository()->removeByCollection($nodeMemberCollectionToRemove);
		}

		if (!$this->available || $this->departmentCollection->empty())
		{
			return;
		}

		$this->assignUserWithExistingMemberMap($user, $existingMemberMap);
	}

	public function reassignUsers(
		UserCollection $userCollection,
	): void
	{
		$currentNodeMemberCollection = Container::getNodeMemberRepository()->findAllByEntityIdsAndEntityTypeAndNodeType(
			entityIds: $userCollection->getIds(),
			entityType: MemberEntityType::USER,
			nodeType: NodeEntityType::DEPARTMENT,
		);
		$existingMemberMap = $this->buildExistingMemberMap($currentNodeMemberCollection);
		$newDepartmentsIds = $this->departmentCollection->map(
			fn (\Bitrix\Intranet\Entity\Department $department) => $department->getId(),
		);
		$nodeMemberCollectionToRemove = $currentNodeMemberCollection->filter(
			fn(NodeMember $nodeMember) => !in_array($nodeMember->nodeId, $newDepartmentsIds),
		);

		if (!$nodeMemberCollectionToRemove->empty())
		{
			HumanResources\Service\Container::getNodeMemberRepository()->removeByCollection($nodeMemberCollectionToRemove);
		}

		if (!$this->available || $this->departmentCollection->empty() || $userCollection->empty())
		{
			return;
		}

		$this->assignUsersWithExistingMemberMap($userCollection, $existingMemberMap);
	}

	private function assignUsersWithExistingMemberMap(
		UserCollection $userCollection,
		array $existingMemberMap,
	): void
	{
		$roleId = HumanResources\Service\Container::getRoleHelperService()->getEmployeeRoleId();
		$memberCollection = new HumanResources\Item\Collection\NodeMemberCollection();

		foreach ($this->departmentCollection as $department)
		{
			foreach ($userCollection as $user)
			{
				if (isset($existingMemberMap[$user->getId()][$department->getId()]))
				{
					continue;
				}

				$memberCollection->add(
					new HumanResources\Item\NodeMember(
						entityType: HumanResources\Type\MemberEntityType::USER,
						entityId: $user->getId(),
						nodeId: $department->getId(),
						active: true,
						role: $roleId,
					),
				);
			}
		}

		if (!$memberCollection->empty())
		{
			HumanResources\Service\Container::getNodeMemberRepository()->createByCollection($memberCollection);
		}
	}

	private function assignUserWithExistingMemberMap(
		User $user,
		array $existingMemberMap,
	): void
	{
		$roleId = HumanResources\Service\Container::getRoleHelperService()->getEmployeeRoleId();
		$memberCollection = new HumanResources\Item\Collection\NodeMemberCollection();

		foreach ($this->departmentCollection as $department)
		{
			if (isset($existingMemberMap[$user->getId()][$department->getId()]))
			{
				continue;
			}

			$memberCollection->add(
				new HumanResources\Item\NodeMember(
					entityType: HumanResources\Type\MemberEntityType::USER,
					entityId: $user->getId(),
					nodeId: $department->getId(),
					active: true,
					role: $roleId,
				),
			);
		}

		if (!$memberCollection->empty())
		{
			HumanResources\Service\Container::getNodeMemberRepository()->createByCollection($memberCollection);
		}
	}

	private function getExistingMemberMap(array $userIds): array
	{
		if (empty($userIds))
		{
			return [];
		}

		$currentNodeMemberCollection = Container::getNodeMemberRepository()->findAllByEntityIdsAndEntityTypeAndNodeType(
			entityIds: $userIds,
			entityType: MemberEntityType::USER,
			nodeType: NodeEntityType::DEPARTMENT,
		);

		return $this->buildExistingMemberMap($currentNodeMemberCollection);
	}

	private function buildExistingMemberMap(NodeMemberCollection $nodeMemberCollection): array
	{
		$result = [];

		foreach ($nodeMemberCollection as $nodeMember)
		{
			$result[$nodeMember->entityId][$nodeMember->nodeId] = true;
		}

		return $result;
	}
}

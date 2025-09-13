<?php

namespace Bitrix\Intranet\Integration\HumanResources;

use Bitrix\HumanResources;
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

		$roleId = HumanResources\Service\Container::getRoleHelperService()->getEmployeeRoleId();
		$memberCollection = new HumanResources\Item\Collection\NodeMemberCollection();

		foreach ($this->departmentCollection as $department)
		{
			foreach ($userCollection as $user)
			{
				$memberCollection->add(
					new HumanResources\Item\NodeMember(
						entityType: HumanResources\Type\MemberEntityType::USER,
						entityId: $user->getId(),
						nodeId: $department->getId(),
						active: true,
						role: $roleId,
					)
				);
			}
		}

		if (!$memberCollection->empty())
		{
			HumanResources\Service\Container::getNodeMemberRepository()->createByCollection($memberCollection);
		}
	}

	public function assignUser(
		User $user,
	): void
	{
		if (!$this->available || $this->departmentCollection->empty())
		{
			return;
		}

		$roleId = HumanResources\Service\Container::getRoleHelperService()->getEmployeeRoleId();
		$memberCollection = new HumanResources\Item\Collection\NodeMemberCollection();

		foreach ($this->departmentCollection as $department)
		{
			$memberCollection->add(
				new HumanResources\Item\NodeMember(
					entityType: HumanResources\Type\MemberEntityType::USER,
					entityId: $user->getId(),
					nodeId: $department->getId(),
					active: true,
					role: $roleId,
				)
			);
		}

		if (!$memberCollection->empty())
		{
			HumanResources\Service\Container::getNodeMemberRepository()->createByCollection($memberCollection);
		}
	}
}
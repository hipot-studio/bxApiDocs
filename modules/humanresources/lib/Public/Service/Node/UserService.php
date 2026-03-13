<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Public\Service\Node;

use Bitrix\HumanResources\Builder\Structure\Filter\Column\EntityIdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeMemberFilter;
use Bitrix\HumanResources\Builder\Structure\NodeMemberDataBuilder;
use Bitrix\HumanResources\Internals\Repository\Structure\NodeMemberRepository;
use Bitrix\HumanResources\Internals\Service\Container as InternalContainer;
use Bitrix\HumanResources\Item\Collection\NodeMemberCollection;
use Bitrix\HumanResources\Item\NodeMember;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\StructureRole;
use Bitrix\HumanResources\Type\NodeEntityType;

class UserService
{
	private NodeMemberRepository $internalNodeMemberRepository;

	public function __construct()
	{
		$this->internalNodeMemberRepository = InternalContainer::getNodeMemberRepository();
	}

	/**
	 * Returns first NodeMember by user ID and structure roles
	 *
	 * @param int $userId
	 * @param array<StructureRole> $structureRoles
	 *
	 * @return NodeMember|null Found node member or null if not found
	 */
	public function findByUserIdAndStructureRoles(int $userId, array $structureRoles): ?NodeMember
	{
		if (empty($structureRoles))
		{
			return null;
		}

		return
			(new NodeMemberDataBuilder)
				->addFilter(
					new NodeMemberFilter(
						entityIdFilter: EntityIdFilter::fromEntityId($userId),
					),
				)
				->setStructureRoles($structureRoles)
				->get()
		;
	}

	/**
	 * Checks if $userId is manager for $employeeId
	 * For that $userId must be in $employeeId any node or in a chain above
	 * AND $userId role in that node must have higher priority than $employeeId role in the chain.
	 * This method allows comparison between Department and Team roles.
	 * Although it's unclear if we should consider a Deputy of a higher node a manager for the Head of a lower node,
	 * so in that case we return false
	 *
	 * @param int $userId
	 * @param int $employeeId
	 * @return bool
	 * @throws \Bitrix\Main\DB\SqlQueryException
	 */
	public function isManagerForEmployee(int $userId, int $employeeId): bool
	{
		$connectedNodes = $this->internalNodeMemberRepository->getConnectedNodePathsForUsers(
			$userId,
			$employeeId,
		);
		$roleCollection = Container::getRoleRepository()->list();

		foreach ($connectedNodes as $nodeConnection)
		{
			$parentRole = $roleCollection->getItemById((int)$nodeConnection['MEMBER_1_ROLE_ID']);

			// if parent is EMPLOYEE or TEAM_EMPLOYEE, he can't be a manager
			if ((int)$parentRole->priority === 0)
			{
				continue;
			}

			$childRole = $roleCollection->getItemById((int)$nodeConnection['MEMBER_2_ROLE_ID']);

			// for members in one node they strictly need to have different roles to be have manager-subordinate relation
			if (((int)$nodeConnection['DEPTH'] === 0) && ($parentRole->priority > $childRole->priority))
			{
				return true;
			}
			// for members in different node: the parent node head is considered a manager for the child node head
			elseif ($parentRole->priority >= $childRole->priority)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the nearest heads from the branch where the given user belongs
	 */
	public function getUserHeads(int $userId, NodeEntityType $nodeEntityType): NodeMemberCollection
	{
		try
		{
			return InternalContainer::getNodeMemberService()->getNodeMemberHeads(
				entityId: $userId,
				nodeEntityType: $nodeEntityType,
			);
		}
		catch (\Throwable)
		{
			return new NodeMemberCollection();
		}
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Public\Service\Node;

use Bitrix\HumanResources\Builder\Structure\Filter\Column\EntityIdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeMemberFilter;
use Bitrix\HumanResources\Builder\Structure\NodeMemberDataBuilder;
use Bitrix\HumanResources\Exception\WrongStructureItemException;
use Bitrix\HumanResources\Internals\Service\Container as InternalContainer;
use Bitrix\HumanResources\Item\Collection\NodeMemberCollection;
use Bitrix\HumanResources\Item\NodeMember;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\StructureRole;
use Bitrix\Main\DB\SqlQueryException;

class UserService
{
	//region role checks
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
	 * Returns all NodeMembers by user ID and structure roles
	 *
	 * @param int $userId
	 * @param array<StructureRole> $structureRoles
	 *
	 * @return NodeMemberCollection
	 */
	public function findAllByUserIdAndStructureRoles(
		int $userId,
		array $structureRoles,
	): NodeMemberCollection
	{
		if (empty($structureRoles))
		{
			return new NodeMemberCollection();
		}

		return
			(new NodeMemberDataBuilder)
				->addFilter(
					new NodeMemberFilter(
						entityIdFilter: EntityIdFilter::fromEntityId($userId),
						entityType: MemberEntityType::USER,
					),
				)
				->setStructureRoles($structureRoles)
				->getAll()
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
	 * @param array<NodeEntityType>|null $nodeTypes
	 * @return bool
	 * @throws SqlQueryException
	 */
	public function isManagerForEmployee(int $userId, int $employeeId, ?array $nodeTypes = null): bool
	{
		$connectedNodes = InternalContainer::getNodeMemberRepository()->getConnectedNodePathsForUsers(
			$userId,
			$employeeId,
			$nodeTypes,
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
	//endregion

	//region hierarchy
	/**
	 * Returns subordinates for a manager.
	 * When $direct is true: returns deputies and employees of the managed node(s), plus heads of direct child nodes.
	 * When $direct is false: returns all members from the entire subtree of the managed node(s).
	 *
	 * @param int $userId
	 * @param NodeEntityType $nodeEntityType
	 * @param bool $direct
	 * @return int[]
	 */
	public function getSubordinateUserIds(int $userId, NodeEntityType $nodeEntityType, bool $direct = true): array
	{
		return InternalContainer::getNodeMemberService()->getSubordinates(
			entityId: $userId,
			nodeEntityType: $nodeEntityType,
			direct: $direct,
		)->getUniqueEntityIds();
	}

	/**
	 * Returns the nearest heads from the branch where the given user belongs
	 */
	public function getUserHeads(int $userId, NodeEntityType $nodeEntityType): NodeMemberCollection
	{
		try
		{
			$role = InternalContainer::getRoleService()->getHeadRoleByNodeType($nodeEntityType);

			return InternalContainer::getNodeMemberService()->getNearestNodeMembersByRole(
				entityId: $userId,
				role: $role,
				memberEntityType: MemberEntityType::USER,
				nodeEntityType: $nodeEntityType,
			);
		}
		catch (\Throwable)
		{
			return new NodeMemberCollection();
		}
	}

	/**
	 * Returns the nearest deputies from the branch where the given user belongs
	 */
	public function getUserDeputies(int $userId, NodeEntityType $nodeEntityType): NodeMemberCollection
	{
		try
		{
			$role = InternalContainer::getRoleService()->getDeputyRoleByNodeType($nodeEntityType);

			return InternalContainer::getNodeMemberService()->getNearestNodeMembersByRole(
				entityId: $userId,
				role: $role,
				memberEntityType: MemberEntityType::USER,
				nodeEntityType: $nodeEntityType,
			);
		}
		catch (\Throwable)
		{
			return new NodeMemberCollection();
		}
	}

	/**
	 * Returns managers (heads and optionally deputies) for multiple users
	 *
	 * @param int[] $userIds
	 * @param array<NodeEntityType> $nodeEntityTypes
	 * @param bool $includeDeputies
	 *
	 * @return array<int, NodeMemberCollection> Map of userId => NodeMemberCollection of managers
	 * @throws WrongStructureItemException
	 */
	public function getUsersManagers(
		array $userIds,
		array $nodeEntityTypes,
		bool $includeDeputies = false,
	): array
	{
		$result = [];

		foreach ($userIds as $userId)
		{
			$userId = (int)$userId;
			if ($userId <= 0)
			{
				continue;
			}

			$managers = new NodeMemberCollection();

			foreach ($nodeEntityTypes as $nodeEntityType)
			{
				$heads = $this->getUserHeads($userId, $nodeEntityType);
				foreach ($heads as $head)
				{
					$managers->add($head);
				}

				if ($includeDeputies)
				{
					$deputies = $this->getUserDeputies($userId, $nodeEntityType);
					foreach ($deputies as $deputy)
					{
						$managers->add($deputy);
					}
				}
			}

			$result[$userId] = $managers;
		}

		return $result;
	}
	//endregion
}

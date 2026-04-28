<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Public\Service\Team;

use Bitrix\HumanResources\Builder\Structure\Filter\Column\EntityIdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\IdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\Node\NodeTypeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeMemberFilter;
use Bitrix\HumanResources\Builder\Structure\NodeDataBuilder;
use Bitrix\HumanResources\Builder\Structure\NodeMemberDataBuilder;
use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Enum\Direction;
use Bitrix\HumanResources\Exception\WrongStructureItemException;
use Bitrix\HumanResources\Internals\Service\Container as InternalContainer;
use Bitrix\HumanResources\Item\Collection\NodeCollection;
use Bitrix\HumanResources\Item\Collection\NodeMemberCollection;
use Bitrix\HumanResources\Public\Service\Container as PublicContainer;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\StructureRole;
use Bitrix\Main\DB\SqlQueryException;

class UserService
{
	//region node chains
	/**
	 * @param int $userId
	 * @param Direction $orderDirection
	 *
	 * @return list<NodeCollection>
	 * @throws WrongStructureItemException
	 */
	public function getTeamChainsByUserId(int $userId, Direction $orderDirection = Direction::ROOT): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		$nodeDataBuilder = new NodeMemberDataBuilder();
		$nodeFilter = new NodeFilter(
			entityTypeFilter: NodeTypeFilter::fromNodeType(NodeEntityType::TEAM),
		);
		$filter =
			new NodeMemberFilter(
				entityIdFilter: EntityIdFilter::fromEntityId($userId),
				nodeFilter: $nodeFilter,
				findRelatedMembers: false,
			);

		$currentTeamMembers = $nodeDataBuilder
			->setFilter($filter)
			->getAll();

		if (empty($currentTeamMembers->getNodeIds()))
		{
			return [];
		}

		$nodeFilter = new NodeFilter(
			idFilter: idFilter::fromIds($currentTeamMembers->getNodeIds()),
			entityTypeFilter: NodeTypeFilter::fromNodeType(NodeEntityType::TEAM),
			direction: Direction::ROOT,
			depthLevel: DepthLevel::FULL,
		);
		$fullNodeCollection = (new NodeDataBuilder())->setFilter($nodeFilter)->getAll()->orderMapByInclude();

		$result = [];
		foreach ($fullNodeCollection as $node)
		{
			if (!in_array($node->id, $currentTeamMembers->getNodeIds(), true))
			{
				continue;
			}

			$chain = [];
			$currentNode = $node;
			while ($currentNode)
			{
				array_unshift($chain, $currentNode);
				$currentNode = $fullNodeCollection->getItemById($currentNode->parentId) ?? null;
			}

			if ($orderDirection === Direction::ROOT)
			{
				$chain = array_reverse($chain, true);
			}

			$result[] = new NodeCollection(...$chain);
		}

		return $result;
	}
	//endregion

	//region role checks
	/**
	 * Returns true if user is head of any team
	 *
	 * @param int $userId
	 *
	 * @return bool
	 */
	public function isHeadOfTeam(int $userId): bool
	{
		$headMember = PublicContainer::getUserService()->findByUserIdAndStructureRoles(
			$userId,
			[StructureRole::TEAM_HEAD],
		);

		return $headMember !== null;
	}

	/**
	 * Returns true if user is head of any team
	 *
	 * @param int $userId
	 *
	 * @return bool
	 */
	public function isDeputyOfTeam(int $userId): bool
	{
		$headMember = PublicContainer::getUserService()->findByUserIdAndStructureRoles(
			$userId,
			[StructureRole::TEAM_DEPUTY_HEAD],
		);

		return $headMember !== null;
	}

	/**
	 * Returns true if user is head or deputy of any team
	 *
	 * @param int $userId
	 *
	 * @return bool
	 */
	public function isHeadOrDeputyOfTeam(int $userId): bool
	{
		$headMember = PublicContainer::getUserService()->findByUserIdAndStructureRoles(
			$userId,
			[StructureRole::TEAM_HEAD, StructureRole::TEAM_DEPUTY_HEAD],
		);

		return $headMember !== null;
	}

	/**
	 * Checks if $userId is manager for $employeeId within team hierarchy
	 *
	 * @param int $userId
	 * @param int $employeeId
	 * @return bool
	 * @throws SqlQueryException
	 */
	public function isManagerForEmployee(int $userId, int $employeeId): bool
	{
		return PublicContainer::getUserService()->isManagerForEmployee(
			$userId,
			$employeeId,
			[NodeEntityType::TEAM],
		);
	}

	/**
	 * Returns IDs of teams where user is a head
	 *
	 * @param int $userId
	 * @return int[]
	 */
	public function getTeamIdsWhereUserIsHead(int $userId): array
	{
		$nodeMembers = PublicContainer::getUserService()->findAllByUserIdAndStructureRoles(
			$userId,
			[StructureRole::TEAM_HEAD],
		);

		return $nodeMembers->getNodeIds();
	}

	/**
	 * Returns IDs of teams where user is a deputy head
	 *
	 * @param int $userId
	 * @return int[]
	 */
	public function getTeamIdsWhereUserIsDeputy(int $userId): array
	{
		$nodeMembers = PublicContainer::getUserService()->findAllByUserIdAndStructureRoles(
			$userId,
			[StructureRole::TEAM_DEPUTY_HEAD],
		);

		return $nodeMembers->getNodeIds();
	}

	/**
	 * Returns all teams where user is a head or deputy head
	 *
	 * @param int $userId
	 * @return NodeMemberCollection
	 */
	public function getNodeMembersWhereUserIsHeadOrDeputy(int $userId): NodeMemberCollection
	{
		return PublicContainer::getUserService()->findAllByUserIdAndStructureRoles(
			$userId,
			[StructureRole::TEAM_HEAD, StructureRole::TEAM_DEPUTY_HEAD],
		);
	}
	//endregion

	//region hierarchy
	/**
	 * Returns subordinates for a team head.
	 * When $direct is true: returns deputies and employees of the team, plus heads of direct child teams.
	 * When $direct is false: returns all members from the entire subtree of the managed team(s).
	 *
	 * @param int $userId
	 * @param bool $direct
	 * @return int[]
	 */
	public function getSubordinateUserIds(int $userId, bool $direct = true): array
	{
		return PublicContainer::getUserService()->getSubordinateUserIds(
			$userId,
			NodeEntityType::TEAM,
			$direct,
		);
	}

	/**
	 * Returns the nearest team heads from the branch where the given user belongs
	 */
	public function getUserHeads(int $userId): NodeMemberCollection
	{
		try
		{
			$role = InternalContainer::getRoleService()->getHeadRoleByNodeType(NodeEntityType::TEAM);

			return InternalContainer::getNodeMemberService()->getNearestNodeMembersByRole(
				entityId: $userId,
				role: $role,
				memberEntityType: MemberEntityType::USER,
				nodeEntityType: NodeEntityType::TEAM,
			);
		}
		catch (\Throwable)
		{
			return new NodeMemberCollection();
		}
	}

	/**
	 * Returns the nearest team deputies from the branch where the given user belongs
	 */
	public function getUserDeputies(int $userId): NodeMemberCollection
	{
		try
		{
			$role = InternalContainer::getRoleService()->getDeputyRoleByNodeType(NodeEntityType::TEAM);

			return InternalContainer::getNodeMemberService()->getNearestNodeMembersByRole(
				entityId: $userId,
				role: $role,
				memberEntityType: MemberEntityType::USER,
				nodeEntityType: NodeEntityType::TEAM,
			);
		}
		catch (\Throwable)
		{
			return new NodeMemberCollection();
		}
	}

	/**
	 * Returns managers (heads and optionally deputies) for multiple users within team hierarchy
	 *
	 * @param int[] $userIds
	 * @param bool $includeDeputies
	 *
	 * @return array<int, NodeMemberCollection> Map of userId => NodeMemberCollection of managers
	 * @throws WrongStructureItemException
	 */
	public function getUsersManagers(array $userIds, bool $includeDeputies = false): array
	{
		return PublicContainer::getUserService()->getUsersManagers(
			$userIds,
			[NodeEntityType::TEAM],
			$includeDeputies,
		);
	}
	//endregion
}

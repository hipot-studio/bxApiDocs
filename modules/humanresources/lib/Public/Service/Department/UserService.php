<?php

namespace Bitrix\HumanResources\Public\Service\Department;

use Bitrix\HumanResources\Exception\WrongStructureItemException;
use Bitrix\HumanResources\Internals\Service\Container as InternalContainer;
use Bitrix\HumanResources\Item\Collection\NodeMemberCollection;
use Bitrix\HumanResources\Public\Service\Container as PublicContainer;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Item\NodeMember;
use Bitrix\HumanResources\Util\StructureHelper;
use Bitrix\Main\DB\SqlQueryException;

class UserService
{
	//region role checks
	/**
	 * Returns true if user is head of any department, excluding teams
	 *
	 * @param int $userId
	 *
	 * @return bool
	 */
	public function isHeadOfDepartment(int $userId): bool
	{
		$headMember = PublicContainer::getUserService()->findByUserIdAndRoleXmlIds(
			$userId,
			[NodeMember::DEFAULT_ROLE_XML_ID['HEAD']],
		);

		return $headMember !== null;
	}

	/**
	 * Returns true if user is deputy of any department, excluding teams
	 *
	 * @param int $userId
	 *
	 * @return bool
	 */
	public function isDeputyOfDepartment(int $userId): bool
	{
		$headMember = PublicContainer::getUserService()->findByUserIdAndRoleXmlIds(
			$userId,
			[NodeMember::DEFAULT_ROLE_XML_ID['DEPUTY_HEAD']],
		);

		return $headMember !== null;
	}

	/**
	 * Returns true if user is head or deputy of any department, excluding teams
	 *
	 * @param int $userId
	 *
	 * @return bool
	 */
	public function isHeadOrDeputyOfDepartment(int $userId): bool
	{
		$headMember = PublicContainer::getUserService()->findByUserIdAndRoleXmlIds(
			$userId,
			[NodeMember::DEFAULT_ROLE_XML_ID['HEAD'], NodeMember::DEFAULT_ROLE_XML_ID['DEPUTY_HEAD']],
		);

		return $headMember !== null;
	}

	/**
	 * Checks if $userId is manager for $employeeId within department hierarchy
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
			[NodeEntityType::DEPARTMENT],
		);
	}

	/**
	 * Returns IDs of departments where user is a head
	 *
	 * @param int $userId
	 * @return int[]
	 */
	public function getDepartmentIdsWhereUserIsHead(int $userId): array
	{
		$nodeMembers = PublicContainer::getUserService()->findAllByUserIdAndRoleXmlIds(
			$userId,
			[NodeMember::DEFAULT_ROLE_XML_ID['HEAD']],
		);

		return $nodeMembers->getNodeIds();
	}

	/**
	 * Returns IDs of departments where user is a deputy head
	 *
	 * @param int $userId
	 * @return int[]
	 */
	public function getDepartmentIdsWhereUserIsDeputy(int $userId): array
	{
		$nodeMembers = PublicContainer::getUserService()->findAllByUserIdAndRoleXmlIds(
			$userId,
			[NodeMember::DEFAULT_ROLE_XML_ID['DEPUTY_HEAD']],
		);

		return $nodeMembers->getNodeIds();
	}

	/**
	 * Returns all departments where user is a head or deputy head
	 *
	 * @param int $userId
	 * @return NodeMemberCollection
	 */
	public function getNodeMembersWhereUserIsHeadOrDeputy(int $userId): NodeMemberCollection
	{
		return PublicContainer::getUserService()->findAllByUserIdAndRoleXmlIds(
			$userId,
			[NodeMember::DEFAULT_ROLE_XML_ID['HEAD'], NodeMember::DEFAULT_ROLE_XML_ID['DEPUTY_HEAD']],
		);
	}
	//endregion

	//region hierarchy
	/**
	 * Returns subordinates for a department head.
	 * When $direct is true: returns deputies and employees of the department, plus heads of direct child departments.
	 * When $direct is false: returns all members from the entire subtree of the managed department(s).
	 *
	 * @param int $userId
	 * @param bool $direct
	 *
	 * @return int[]
	 */
	public function getSubordinateUserIds(int $userId, bool $direct = true): array
	{
		return PublicContainer::getUserService()->getSubordinateUserIds(
			$userId,
			NodeEntityType::DEPARTMENT,
			$direct,
		);
	}

	/**
	 * Returns the nearest department heads from the branch where the given user belongs
	 */
	public function getUserHeads(int $userId): NodeMemberCollection
	{
		try
		{
			$role = InternalContainer::getRoleService()->getHeadRoleByNodeType(NodeEntityType::DEPARTMENT);

			return InternalContainer::getNodeMemberService()->getNearestNodeMembersByRole(
				entityId: $userId,
				role: $role,
				memberEntityType: MemberEntityType::USER,
				nodeEntityType: NodeEntityType::DEPARTMENT,
			);
		}
		catch (\Throwable)
		{
			return new NodeMemberCollection();
		}
	}

	/**
	 * Returns the nearest department deputies from the branch where the given user belongs
	 */
	public function getUserDeputies(int $userId): NodeMemberCollection
	{
		try
		{
			$role = InternalContainer::getRoleService()->getDeputyRoleByNodeType(NodeEntityType::DEPARTMENT);

			return InternalContainer::getNodeMemberService()->getNearestNodeMembersByRole(
				entityId: $userId,
				role: $role,
				memberEntityType: MemberEntityType::USER,
				nodeEntityType: NodeEntityType::DEPARTMENT,
			);
		}
		catch (\Throwable)
		{
			return new NodeMemberCollection();
		}
	}

	/**
	 * Returns managers (heads and optionally deputies) for multiple users within department hierarchy
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
			[NodeEntityType::DEPARTMENT],
			$includeDeputies,
		);
	}
	//endregion

	//region utils
	/**
	 * Filters user IDs, returning only those who are employees.
	 *
	 * @param int[] $userIds
	 * @return int[]
	 */
	public function filterEmployeeIds(array $userIds): array
	{
		$filteredIds = array_filter($userIds, static fn($id) => is_numeric($id) && (int)$id > 0);
		$intIds = array_map('intval', $filteredIds);

		if (empty($userIds))
		{
			return [];
		}

		return InternalContainer::getNodeMemberRepository()->getExistingEntityIds($intIds);
	}

	public function getTotalEmployeeCount(): int
	{
		$structure = StructureHelper::getDefaultStructure();
		if (!$structure)
		{
			return 0;
		}

		$rootDepartment =  Container::getNodeRepository()->getRootNodeByStructureId($structure->id);
		if (!$rootDepartment)
		{
			return 0;
		}

		return InternalContainer::getNodeMemberRepository()->countUniqueUsersByNodeIdWithSubNodes($rootDepartment->id);
	}

	/**
	 * Returns true if the user has at least one node_member row on an existing DEPARTMENT node.
	 *
	 * @param int $userId
	 * @return bool
	 */
	public function isEmployee(int $userId): bool
	{
		return Container::getUserService()->isEmployee($userId);
	}
	//endregion

	//region mutators
	/**
	 * Moves the user into a single target department and clears the rest of their
	 * department links. Intended for restoring users that ended up in a broken state
	 * (no department links, only orphan rows, or links spread across stale departments).
	 *
	 * After the call {@see isEmployee()} is guaranteed to return true.
	 *
	 * Thin wrapper over {@see assignToDepartments()} with replace semantics. UF_DEPARTMENT is
	 * synchronised in the background by
	 * {@see \Bitrix\HumanResources\Compatibility\Event\NewToOldEventHandler} — this method
	 * never writes UF_DEPARTMENT directly.
	 *
	 * @param int $userId
	 * @param int $departmentId DEPARTMENT node ID
	 * @param int|null $structureId structure the department belongs to; null resolves to the default structure
	 *
	 * @throws \InvalidArgumentException if $departmentId does not point to a DEPARTMENT node of the structure
	 */
	public function assignToSingleDepartment(int $userId, int $departmentId, ?int $structureId = null): void
	{
		$this->assignToDepartments($userId, [$departmentId], replaceExisting: true, structureId: $structureId);
	}

	/**
	 * Assigns the user to the given set of DEPARTMENT nodes.
	 *
	 * By default ($replaceExisting = false) the passed departments are ADDED to the user's
	 * current department links without removing the rest. When $replaceExisting is true,
	 * department links absent from $departmentIds are removed (replace semantics) — this is how
	 * {@see assignToSingleDepartment()} restores a user into a single department.
	 *
	 * For each department a membership is created or reactivated. UF_DEPARTMENT is synchronised
	 * in the background by {@see \Bitrix\HumanResources\Compatibility\Event\NewToOldEventHandler} —
	 * this method never writes UF_DEPARTMENT directly.
	 *
	 * @param int $userId
	 * @param int[] $departmentIds non-empty list of DEPARTMENT node IDs
	 * @param bool $replaceExisting when true, also removes department links absent from $departmentIds
	 * @param int|null $structureId structure the departments belong to; null resolves to the default structure
	 *
	 * @throws \InvalidArgumentException if $departmentIds is empty or contains an ID that is not a DEPARTMENT node of the structure
	 */
	public function assignToDepartments(
		int $userId,
		array $departmentIds,
		bool $replaceExisting = false,
		?int $structureId = null,
	): void
	{
		InternalContainer::getNodeMemberService()->assignUserToDepartments(
			$userId,
			$departmentIds,
			$replaceExisting,
			$structureId,
		);
	}
	//endregion
}

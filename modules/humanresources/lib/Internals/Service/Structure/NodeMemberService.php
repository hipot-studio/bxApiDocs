<?php

namespace Bitrix\HumanResources\Internals\Service\Structure;

use Bitrix\HumanResources\Builder\Structure\Filter\Column\EntityIdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\IdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\Node\NodeTypeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\RoleFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeMemberFilter;
use Bitrix\HumanResources\Builder\Structure\NodeMemberDataBuilder;
use Bitrix\HumanResources\Exception\UpdateFailedException;
use Bitrix\HumanResources\Exception\WrongStructureItemException;
use Bitrix\HumanResources\Internals\Service\Container as InternalContainer;
use Bitrix\HumanResources\Item;
use Bitrix\HumanResources\Item\Collection\NodeMemberCollection;
use Bitrix\HumanResources\Item\NodeMember;
use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Enum\NodeActiveFilter;
use Bitrix\HumanResources\Repository\NodeMemberRepository;
use Bitrix\HumanResources\Repository\NodeSettingsRepository;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Service\UserService as InternalUserService;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\NodeMemberRole;
use Bitrix\HumanResources\Type\StructureAction;
use Bitrix\HumanResources\Type\NodeSettingsType;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;

class NodeMemberService
{
	private const NODE_MEMBERS_BY_ROLE_CACHE_KEY = 'nearest_node_members_%s_%s_%s_%d';
	private const SUBORDINATES_CACHE_KEY = 'subordinates_%s_%s_%d_%d';
	private NodeMemberRepository $nodeMemberRepository;
	private NodeSettingsRepository $nodeSettingsRepository;

	public function __construct()
	{
		$this->nodeMemberRepository = Container::getNodeMemberRepository();
		$this->nodeSettingsRepository = Container::getNodeSettingsRepository();
	}

	//region mutators
	/**
	 * Move a single member to another node. Validates node type match and role correctness,
	 * throws UpdateFailedException on invalid input.
	 *
	 * For batch moving users by role XML IDs (without strict validation — silently skips invalid roles),
	 * use {@see \Bitrix\HumanResources\Service\NodeMemberService::moveUsersToDepartment()} instead.
	 *
	 * @throws UpdateFailedException
	 * @throws ArgumentException
	 * @throws SqlQueryException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function moveMember(Item\NodeMember $nodeMember, Item\Node $node, ?Item\Role $role = null): Item\NodeMember
	{
		$nodeMember = $this->nodeMemberRepository->findById((int)$nodeMember->id);
		if (!$nodeMember)
		{
			throw (new UpdateFailedException(
				'Node member not found',
			));
		}

		if ($nodeMember->node?->type !== $node->type)
		{
			throw (new UpdateFailedException(
				'Wrong target node type',
			));
		}

		$sourceNode = $nodeMember->node;
		$sourceRoleId = (int)($nodeMember->roles[0] ?? 0);

		if ($role)
		{
			if (!$this->isRoleCorrectForNode($node, $role))
			{
				throw (new UpdateFailedException(
					'Wrong role for target node type',
				));
			}

			$nodeMember->role = $role->id;
		}

		// delete all settings for the current node that related to this member
		// node type check is valid as long as settings with userIds are only available team nodes. If that changes, delete this check
		if ($nodeMember->nodeId !== $node->id && $node->type === NodeEntityType::TEAM)
		{
			$this->nodeSettingsRepository->removeByTypeAndNodeId(
				$nodeMember->nodeId,
				NodeSettingsType::getCasesWithUserIdsValue(),
				$nodeMember->entityId,
			);
		}

		$nodeMember->nodeId = $node->id;
		$this->nodeMemberRepository->update($nodeMember);

		InternalContainer::getNodeMemberNotificationService()->sendAllMoveMemberNotifications(
			$nodeMember,
			$sourceNode,
			$node,
			$sourceRoleId,
			$role,
		);

		return $nodeMember;
	}

	/**
	 * Add users to the node with the given role, or update their role if they are already members.
	 * Does not remove members that are absent from the input list.
	 *
	 * Returns an empty collection if the role is not allowed for the node type.
	 * Non-existing user IDs are filtered out.
	 *
	 * @param Item\Node $node
	 * @param int[] $userIds user IDs to add or update
	 * @param Item\Role $role role to assign
	 *
	 * @return NodeMemberCollection members that were added or updated
	 */
	public function addOrUpdateUsersInNode(Item\Node $node, array $userIds, Item\Role $role): NodeMemberCollection
	{
		$result = new NodeMemberCollection();

		$isRoleAllowed = in_array(
			$role->xmlId,
			NodeMemberRole::allowedValuesForNodeType($node->type),
			true,
		);
		if (!$isRoleAllowed)
		{
			return $result;
		}

		$existingUserIds = InternalContainer::getNodeMemberRepository()->getExistingEntityIds(
			array_map('intval', array_filter($userIds, static fn($id) => is_numeric($id) && (int)$id > 0)),
		);
		if (empty($existingUserIds))
		{
			return $result;
		}

		foreach ($existingUserIds as $userId)
		{
			$result->add(new NodeMember(
				entityType: MemberEntityType::USER,
				entityId: (int)$userId,
				nodeId: $node->id,
				active: true,
				role: $role->id,
			));
		}

		$this->nodeMemberRepository->createByCollection($result);
		$notificationService = InternalContainer::getNodeMemberNotificationService();
		$notificationService->preloadEmployeeNames($result);
		$notificationService->sendAllAddMemberNotifications($result, $node, $role);

		return $result;
	}

	/**
	 * Assigns the user to the given set of DEPARTMENT nodes.
	 *
	 * By default ($replaceExisting = false) the passed departments are added to the user's
	 * current department links without touching the rest. When $replaceExisting is true,
	 * department links absent from the passed set are removed (replace semantics, matching
	 * {@see \Bitrix\HumanResources\Public\Service\Department\UserService::assignToSingleDepartment()}).
	 *
	 * For each department the link is created or reactivated. Reactivation goes through
	 * {@see \Bitrix\HumanResources\Internals\Repository\Structure\NodeMemberRepository::setActiveById()}
	 * so OnMemberUpdated is emitted even for an already-active link — this keeps the
	 * compatibility-sync recovery of UF_DEPARTMENT working. UF_DEPARTMENT is never written directly.
	 *
	 * @param int $userId
	 * @param int[] $departmentIds non-empty list of DEPARTMENT node IDs
	 * @param bool $replaceExisting when true, also removes department links absent from $departmentIds
	 * @param int|null $structureId structure the departments belong to; null resolves to the default structure
	 *
	 * @throws \InvalidArgumentException if $departmentIds is empty or contains an ID that is not a DEPARTMENT node of the structure
	 */
	public function assignUserToDepartments(
		int $userId,
		array $departmentIds,
		bool $replaceExisting = false,
		?int $structureId = null,
	): void
	{
		if (empty($departmentIds))
		{
			throw new \InvalidArgumentException('Department list must not be empty');
		}

		$targetIds = array_values(array_unique(array_map('intval', $departmentIds)));

		// Validate all targets in a single query before any mutation: findAllByIds returns only
		// DEPARTMENT nodes of $structureId (null resolves to the default structure).
		$foundDepartmentIds = [];
		foreach (InternalContainer::getNodeRepository()->findAllByIds(
			nodeIds: $targetIds,
			structureId: $structureId,
			activeFilter: NodeActiveFilter::ALL,
		) as $node)
		{
			$foundDepartmentIds[$node->id] = true;
		}

		foreach ($targetIds as $departmentId)
		{
			if (!isset($foundDepartmentIds[$departmentId]))
			{
				throw new \InvalidArgumentException('Target node is not a valid department');
			}
		}

		// Deliberately not wrapped in a DB transaction: repository writes emit OnMember* events
		// synchronously, and compatibility-sync updates UF_DEPARTMENT outside this transaction,
		// so a rollback would desync them. Atomicity is ensured by the upfront validation above;
		// proper transactional support would require queue-aware repository writes.
		$memberRepository = Container::getNodeMemberRepository();
		$internalMemberRepository = InternalContainer::getNodeMemberRepository();

		$departmentMembers = $memberRepository->findAllByEntityIdAndEntityTypeAndNodeType(
			$userId,
			MemberEntityType::USER,
			NodeEntityType::DEPARTMENT,
			activeFilter: NodeActiveFilter::ALL,
		);

		$existingByNodeId = [];
		foreach ($departmentMembers as $member)
		{
			$existingByNodeId[$member->nodeId] = $member;
		}

		if ($replaceExisting)
		{
			$membersToRemove = new NodeMemberCollection();
			foreach ($departmentMembers as $member)
			{
				if (!in_array($member->nodeId, $targetIds, true))
				{
					$membersToRemove->add($member);
				}
			}

			if (!$membersToRemove->empty())
			{
				$memberRepository->removeByCollection($membersToRemove);
			}
		}

		foreach ($targetIds as $departmentId)
		{
			$existingMember = $existingByNodeId[$departmentId] ?? null;
			if ($existingMember === null)
			{
				$memberRepository->create(new NodeMember(
					entityType: MemberEntityType::USER,
					entityId: $userId,
					nodeId: $departmentId,
					active: true,
				));
			}
			else
			{
				// setActiveById intentionally emits OnMemberUpdated even for a no-op (see docblock).
				$internalMemberRepository->setActiveById($existingMember->id, true);
			}
		}

		Container::getCacheManager()->clean(
			sprintf(InternalUserService::USER_DEPARTMENT_EXISTS_KEY, $userId),
		);
	}
	//endregion

	//region membership queries
	/**
	 * Returns members of DEPARTMENT nodes where the user is head or deputy head.
	 */
	public function getManagedDepartmentNodes(int $userId, ?int $structureId = null): NodeMemberCollection
	{
		return (new NodeMemberDataBuilder())
			->addFilter(new NodeMemberFilter(
				entityIdFilter: EntityIdFilter::fromEntityId($userId),
				nodeFilter: new NodeFilter(
					entityTypeFilter: NodeTypeFilter::createForDepartment(),
					structureId: $structureId,
				),
				roleFilter: RoleFilter::fromRoles(
					NodeMemberRole::Head,
					NodeMemberRole::DeputyHead,
				),
			))
			->getAll()
		;
	}

	public function isUserInMultipleNodes(int $userId, NodeEntityType $nodeEntityType = NodeEntityType::DEPARTMENT): bool
	{
		$nodeMemberCollection = $this->nodeMemberRepository->findAllByEntityIdAndEntityTypeAndNodeType(
			entityId: $userId,
			entityType: MemberEntityType::USER,
			nodeType: $nodeEntityType,
			limit: 2,
		);

		return $nodeMemberCollection->count() > 1;
	}

	/**
	 * Get departments where the user is HEAD or DEPUTY_HEAD, with subordinate counts.
	 * Pass $viewerUserId to filter results by viewer's access permissions.
	 *
	 * @return array<int, array{nodeId: int, name: string, role: string, subordinatesCount: int}>
	 */
	public function getSubordinatesCountByUser(int $userId, ?int $viewerUserId = null): array
	{
		$roleRepository = Container::getRoleRepository();
		$nodeRepository = Container::getNodeRepository();
		$internalNodeRepository = InternalContainer::getNodeRepository();
		$nodeMemberRepository = InternalContainer::getNodeMemberRepository();

		$headRole = $roleRepository->findByXmlId(NodeMemberRole::Head->value);
		$deputyRole = $roleRepository->findByXmlId(NodeMemberRole::DeputyHead->value);

		$managedNodes = [];

		if ($headRole)
		{
			foreach ($nodeRepository->findAllByUserIdAndRoleId($userId, $headRole->id) as $node)
			{
				if ($node->type === NodeEntityType::DEPARTMENT)
				{
					$managedNodes[$node->id] = ['role' => 'HEAD', 'node' => $node];
				}
			}
		}

		if ($deputyRole)
		{
			foreach ($nodeRepository->findAllByUserIdAndRoleId($userId, $deputyRole->id) as $node)
			{
				if ($node->type === NodeEntityType::DEPARTMENT && !isset($managedNodes[$node->id]))
				{
					$managedNodes[$node->id] = ['role' => 'DEPUTY_HEAD', 'node' => $node];
				}
			}
		}

		$result = [];
		foreach ($managedNodes as $entry)
		{
			$node = $entry['node'];

			if ($viewerUserId !== null)
			{
				$visibleNode = $internalNodeRepository->getById(
					$node->id,
					StructureAction::ViewAction,
					$viewerUserId,
				);
				if ($visibleNode === null)
				{
					continue;
				}
			}

			$count = $nodeMemberRepository->countUniqueUsersByNodeIdWithSubNodes($node->id);
			$count = max(0, $count - 1);

			$result[] = [
				'nodeId' => $node->id,
				'name' => $node->name,
				'role' => $entry['role'],
				'subordinatesCount' => $count,
			];
		}

		return $result;
	}
	//endregion

	//region hierarchy
	/**
	 * Returns the nearest members with specified role from the branch where the given entity belongs
	 */
	public function getNearestNodeMembersByRole(
		int $entityId,
		?Item\Role $role,
		MemberEntityType $memberEntityType,
		NodeEntityType $nodeEntityType,
	): NodeMemberCollection
	{
		if (!$role)
		{
			return new NodeMemberCollection();
		}

		$cacheKey = sprintf(
			self::NODE_MEMBERS_BY_ROLE_CACHE_KEY,
			$role->xmlId,
			$nodeEntityType->value,
			$memberEntityType->value,
			$entityId
		);
		$cacheDir = NodeMemberRepository::NODE_MEMBER_CACHE_DIR;
		$cacheData = Container::getCacheManager()->getData($cacheKey, $cacheDir);

		if (isset($cacheData['members']))
		{
			return NodeMemberCollection::wakeUp($cacheData['members']);
		}

		$resultCollection = new NodeMemberCollection();

		$nodeMemberCollection = InternalContainer::getNodeMemberRepository()->findAllByEntityIds(
			entityIds: [$entityId],
			memberEntityType: $memberEntityType,
			nodeTypes: [$nodeEntityType],
		);

		if ($nodeMemberCollection->empty())
		{
			return $resultCollection;
		}

		foreach ($nodeMemberCollection as $nodeMember)
		{
			$node = $nodeMember->node;
			if (!$node)
			{
				continue;
			}

			if ((int)$nodeMember->roles[0] === $role->id)
			{
				$node = Container::getNodeRepository()->getById($node->parentId);
				if (!$node || $node->type !== $nodeEntityType)
				{
					continue;
				}
			}

			$nodeMembersWithRole = new NodeMemberCollection();
			while ($nodeMembersWithRole->empty() && $node)
			{
				$nodeMembersWithRole = InternalContainer::getNodeMemberRepository()
					->findAllByRoleIdAndNodeId($role->id, $node->id)
				;

				if (!$nodeMembersWithRole->empty() || !$node->parentId)
				{
					foreach ($nodeMembersWithRole as $memberWithRole)
					{
						if ($memberWithRole->entityId !== $entityId)
						{
							$resultCollection->add($memberWithRole);
						}
					}

					break;
				}

				$node = Container::getNodeRepository()->getById($node->parentId);
			}
		}

		Container::getCacheManager()->setData($cacheKey, ['members' => $resultCollection->getValues()], $cacheDir);

		return $resultCollection;
	}

	/**
	 * Returns direct subordinates for a manager.
	 * If user is a head of a node, returns: deputies and employees of that node, plus heads of direct child nodes.
	 * Now works only for heads
	 *
	 * @param int $entityId
	 * @param MemberEntityType $memberEntityType
	 * @param NodeEntityType $nodeEntityType
	 * @param bool $direct When true returns only direct subordinates; when false returns all subordinates
	 *                     from the entire subtree, deduplicating overlapping nodes via isAncestor check.
	 *
	 * @return NodeMemberCollection
	 * @throws WrongStructureItemException|ArgumentException
	 */
	public function getSubordinates(
		int $entityId,
		MemberEntityType $memberEntityType = MemberEntityType::USER,
		NodeEntityType $nodeEntityType = NodeEntityType::DEPARTMENT,
		bool $direct = true,
	): NodeMemberCollection
	{
		$cacheKey = sprintf(
			self::SUBORDINATES_CACHE_KEY,
			$nodeEntityType->value,
			$memberEntityType->value,
			$entityId,
			(int)$direct,
		);
		$cacheDir = NodeMemberRepository::NODE_MEMBER_CACHE_DIR;
		$cacheData = Container::getCacheManager()->getData($cacheKey, $cacheDir);

		if (isset($cacheData['subordinates']))
		{
			return NodeMemberCollection::wakeUp($cacheData['subordinates']);
		}

		$resultCollection = new NodeMemberCollection();

		if ($nodeEntityType === NodeEntityType::DEPARTMENT)
		{
			$headRole = NodeMemberRole::Head;
			$deputyRole = NodeMemberRole::DeputyHead;
			$employeeRole = NodeMemberRole::Employee;
		}
		else
		{
			$headRole = NodeMemberRole::TeamHead;
			$deputyRole = NodeMemberRole::TeamDeputyHead;
			$employeeRole = NodeMemberRole::TeamEmployee;
		}

		$userHeadMembers = (new NodeMemberDataBuilder())
			->setFilter(
				new NodeMemberFilter(
					entityIdFilter: EntityIdFilter::fromEntityId($entityId),
					nodeFilter: new NodeFilter(
						entityTypeFilter: NodeTypeFilter::fromNodeType($nodeEntityType),
						depthLevel: DepthLevel::NONE,
					),
					roleFilter: RoleFilter::fromRole($headRole),
				),
			)
			->getAll()
		;

		if($userHeadMembers->empty())
		{
			return $resultCollection;
		}

		$nodeIds = [];
		foreach ($userHeadMembers as $nodeMember)
		{
			$nodeIds[] = $nodeMember->nodeId;
		}

		// Collect deputies and employees of the user's managed node(s) to include them in the result along with employees of child nodes.
		$employeeCollection = (new NodeMemberDataBuilder())
			->setFilter(
				new NodeMemberFilter(
					nodeFilter: new NodeFilter(
						idFilter: idFilter::fromIds($nodeIds),
						entityTypeFilter: NodeTypeFilter::fromNodeType($nodeEntityType),
						depthLevel: DepthLevel::NONE,
					),
					roleFilter: RoleFilter::fromRoles($deputyRole, $employeeRole),
				),
			)
			->getAll()
		;

		if ($direct)
		{
			$subEmployeeCollection = $this->collectDirectSubordinates(
				nodeIds: $nodeIds,
				nodeEntityType: $nodeEntityType,
				headRole: $headRole,
			);
		}
		else
		{
			$subEmployeeCollection = $this->collectAllSubordinates(
				nodeIds: $nodeIds,
				nodeEntityType: $nodeEntityType,
			);
		}

		$resultCollection = new NodeMemberCollection(...[
			...$employeeCollection->getValues(),
			...$subEmployeeCollection->getValues(),
		]);

		$resultCollection = $resultCollection->filter(
			static fn(NodeMember $member) => $member->entityId !== $entityId,
		);

		Container::getCacheManager()->setData($cacheKey, ['subordinates' => $resultCollection->getValues()], $cacheDir);

		return $resultCollection;
	}

	/**
	 * Collects direct subordinates: deputies and employees of the managed node(s),
	 * plus heads of direct child nodes (only for heads, not deputies).
	 *
	 * @param array $nodeIds
	 * @param NodeEntityType $nodeEntityType
	 * @param NodeMemberRole $headRole
	 *
	 * @return NodeMemberCollection
	 * @throws ArgumentException
	 */
	private function collectDirectSubordinates(
		array $nodeIds,
		NodeEntityType $nodeEntityType,
		NodeMemberRole $headRole,
	): NodeMemberCollection
	{
		return (new NodeMemberDataBuilder())
			->setFilter(
				new NodeMemberFilter(
					nodeFilter: new NodeFilter(
						idFilter: idFilter::fromIds($nodeIds),
						entityTypeFilter: NodeTypeFilter::fromNodeType($nodeEntityType),
						depthLevel: DepthLevel::FIRST,
					),
					roleFilter: RoleFilter::fromRole($headRole),
				),
			)
			->getAll()
		;
	}

	/**
	 * Collects all subordinates from the entire subtree of the managed node(s).
	 * When the user manages more than one node, overlapping subtrees are deduplicated
	 * by removing any node that is a descendant of another managed node via isAncestor.
	 *
	 * @param array $nodeIds
	 * @param NodeEntityType $nodeEntityType
	 *
	 * @return NodeMemberCollection
	 * @throws ArgumentException
	 */
	private function collectAllSubordinates(
		array $nodeIds,
		NodeEntityType $nodeEntityType,
	): NodeMemberCollection
	{
		return (new NodeMemberDataBuilder())
			->setFilter(
				new NodeMemberFilter(
					nodeFilter: new NodeFilter(
						idFilter: idFilter::fromIds($nodeIds),
						entityTypeFilter: NodeTypeFilter::fromNodeType($nodeEntityType),
						depthLevel: DepthLevel::FULL,
					),
				),
			)
			->getAll()
		;
	}
	//endregion

	//region helpers
	private function isRoleCorrectForNode(Item\Node $node, Item\Role $role): bool
	{
		return in_array($role->xmlId, NodeMemberRole::allowedValuesForNodeType($node->type), true);
	}
	//endregion
}

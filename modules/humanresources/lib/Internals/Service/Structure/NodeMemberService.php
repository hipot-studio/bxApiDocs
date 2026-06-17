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
use Bitrix\HumanResources\Repository\NodeMemberRepository;
use Bitrix\HumanResources\Repository\NodeSettingsRepository;
use Bitrix\HumanResources\Service\Container;
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

		return $result;
	}

	public function isUserInMultipleNodes(int $userId, NodeEntityType $nodeEntityType = NodeEntityType::DEPARTMENT): bool
	{
		$nodeMemberCollection = $this->nodeMemberRepository->findAllByEntityIdAndEntityTypeAndNodeType(
			entityId: $userId,
			entityType: MemberEntityType::USER,
			nodeType: NodeEntityType::DEPARTMENT,
			limit: 2,
		);

		return $nodeMemberCollection->count() > 1;
	}

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

	private function isRoleCorrectForNode(Item\Node $node, Item\Role $role): bool
	{
		return in_array($role->xmlId, NodeMemberRole::allowedValuesForNodeType($node->type), true);
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
}

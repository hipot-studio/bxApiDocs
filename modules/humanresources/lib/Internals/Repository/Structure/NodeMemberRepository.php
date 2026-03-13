<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Internals\Repository\Structure;

use Bitrix\HumanResources\Builder\Structure\Filter\Column\EntityIdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\IdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\Node\NodeTypeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeMemberFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\SelectionCondition\Node\NodeAccessFilter;
use Bitrix\HumanResources\Builder\Structure\NodeMemberDataBuilder;
use Bitrix\HumanResources\Builder\Structure\Sort\NodeMemberSort;
use Bitrix\HumanResources\Enum\NodeActiveFilter;
use Bitrix\HumanResources\Enum\SortDirection;
use Bitrix\HumanResources\Item\Collection\NodeMemberCollection;
use Bitrix\HumanResources\Item\NodeMember;
use Bitrix\HumanResources\Item\Role;
use Bitrix\HumanResources\Model\NodeMemberTable;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\StructureAction;
use Bitrix\HumanResources\Type\StructureRole;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\SystemException;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;

final class NodeMemberRepository
{
	private const CACHE_TTL = 86400;
	public const NODE_MEMBER_CACHE_DIR = '/node/member/';

	public function findAllByEntityIds(
		array $entityIds,
		MemberEntityType $memberEntityType = MemberEntityType::USER,
		?array $nodeIds = null,
		array $nodeTypes = [NodeEntityType::DEPARTMENT],
		?int $structureId = null,
		?StructureAction $structureAction = null,
		NodeActiveFilter $nodeActiveFilter = NodeActiveFilter::ONLY_GLOBAL_ACTIVE,

	): NodeMemberCollection
	{
		$entityIds = array_map('intval', array_filter($entityIds, 'is_numeric'));
		if (empty($entityIds))
		{
			return new NodeMemberCollection();
		}

		$accessFilter = $structureAction ? new NodeAccessFilter($structureAction) : null;

		$nodeFilter = new NodeFilter(
			idFilter: $nodeIds ? idFilter::fromIds($nodeIds) : null,
			entityTypeFilter: NodeTypeFilter::fromNodeTypes($nodeTypes),
			structureId: $structureId,
			active: $nodeActiveFilter,
			accessFilter: $accessFilter,
		);


		return
			(new NodeMemberDataBuilder())
				->addFilter(
					new NodeMemberFilter(
						entityIdFilter: EntityIdFilter::fromEntityIds($entityIds),
						entityType: $memberEntityType,
						nodeFilter: $nodeFilter,
					),
				)
				->getAll()
		;
	}

	/**
	 * @param NodeEntityType $nodeType
	 * @param bool $active
	 *
	 * @return array
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getMultipleNodeMembers(
		NodeEntityType $nodeType,
		bool $active = true
	): array
	{
		$subQuery = NodeMemberTable::query()
			->setSelect(['ENTITY_ID'])
			->where('ENTITY_TYPE', MemberEntityType::USER->value)
			->where('NODE.TYPE', $nodeType->name)
			->where('ACTIVE', $active ? 'Y' : 'N')
			->registerRuntimeField(
				new ExpressionField('MEMBER_CNT', 'COUNT(DISTINCT %s)', ['NODE_ID'])
			)
			->setGroup(['ENTITY_ID'])
			->where('MEMBER_CNT', '>=', 2)
		;

		$nodeMemberQuery = NodeMemberTable::query()
			->setSelect(['ENTITY_ID', 'NODE_ID'])
			->whereIn('ENTITY_ID', $subQuery)
			->where('NODE.TYPE', $nodeType->name)
			->cacheJoins(true)
			->setCacheTtl(self::CACHE_TTL)
		;

		$nodeMemberArray = [];
		foreach ($nodeMemberQuery->fetchAll() as $nodeMember)
		{
			if (
				$nodeMember['ENTITY_ID'] ?? null
				&& $nodeMember['NODE_ID'] ?? null
			)
			{
				$nodeMemberArray[(int)$nodeMember['ENTITY_ID']][] = (int)$nodeMember['NODE_ID'];
			}
		}

		return $nodeMemberArray;
	}

	public function getExistingEntityIds(
		array $entityIds,
		MemberEntityType $memberEntityType = MemberEntityType::USER,
		?NodeEntityType $nodeType = NodeEntityType::DEPARTMENT,
	): array
	{
		if (empty($entityIds))
		{
			return [];
		}

		$query = NodeMemberTable::query()
			->setSelect(['ENTITY_ID'])
			->where('ENTITY_TYPE', $memberEntityType->value)
			->whereIn('ENTITY_ID', $entityIds)
			->setGroup(['ENTITY_ID'])
			->setCacheTtl(self::CACHE_TTL)
		;

		if ($nodeType)
		{
			$query->where('NODE.TYPE', $nodeType->value);
		}

		$result = [];
		foreach ($query->fetchAll() as $row)
		{
			$result[] = (int)$row['ENTITY_ID'];
		}

		return $result;
	}

	public function countUniqueUsersByNodeIdWithSubNodes(int $nodeId): int
	{
		$cacheManager = Container::getCacheManager();

		$cacheId = 'node_with_subnodes_member_unique_user_count_' . $nodeId;
		$cacheDir = NodeMemberRepository::NODE_MEMBER_CACHE_DIR;

		$result = $cacheManager->getData($cacheId, $cacheDir);
		if ($result !== null)
		{
			return (int)$result;
		}

		$node = Container::getNodeRepository()->getById($nodeId);
		if (!$node)
		{
			return 0;
		}

		try
		{
			$countQuery =
				NodeMemberTable::query()
					->setSelect(['CNT'])
					->registerRuntimeField(
						'',
						new ExpressionField(
							'CNT',
							'COUNT(DISTINCT %s)',
							['ENTITY_ID']
						)
					)
					->where('ACTIVE', 'Y')
					->where('ENTITY_TYPE', MemberEntityType::USER->value)
					->where('NODE.CHILD_NODES.PARENT_ID', $nodeId)
					->where('NODE.TYPE', $node->type->value)
					->setCacheTtl(self::CACHE_TTL)
					->cacheJoins(true)
			;

			$result = $countQuery->fetch();
		}
		catch (\Exception $e)
		{
			return 0;
		}

		$cacheManager->setData($cacheId, $cacheDir, $result['CNT'] ?? 0);

		return (int)($result['CNT'] ?? 0);
	}

	public function findAllByRoleIdAndNodeId(
		int $roleId,
		int $nodeId,
		MemberEntityType $memberEntityType = MemberEntityType::USER,
		?int $limit = null,
		?int $offset = null,
		bool $ascendingSort = true,
	): NodeMemberCollection
	{
		$nodeMemberCollection = new NodeMemberCollection();
		if ($roleId === 0 || $nodeId === 0)
		{
			return $nodeMemberCollection;
		}


		$cacheManager = Container::getCacheManager();
		$cacheDir = NodeMemberRepository::NODE_MEMBER_CACHE_DIR;
		$limit = (int)$limit;
		$offset = (int)$offset;
		$ascendingSort = (int)$ascendingSort;

		$cacheKey =
			"role_{$roleId}"
			. "_node_{$nodeId}"
			. "_memberType_{$memberEntityType->value}"
			. "_limit_{$limit}"
			. "_offset_{$offset}"
			. "_sort_{$ascendingSort}"
		;

		$cacheData = $cacheManager->getData($cacheKey, $cacheDir);
		if (isset($cacheData['nodeMembersArray']))
		{
			return NodeMemberCollection::wakeUp($cacheData['nodeMembersArray']);
		}

		$node = Container::getNodeRepository()->getById($nodeId);
		if (!$node)
		{
			return $nodeMemberCollection;
		}

		$role = Container::getRoleHelperService()->getById($roleId);
		$structureRole = $this->getStructureRoleByRoleItem($role);
		if (!$structureRole)
		{
			return $nodeMemberCollection;
		}

		$nodeFilter = new NodeFilter(
			idFilter: IdFilter::fromId($node->id),
			structureId: $node->structureId,
		);
		$nodeMemberDataBuilder =
			(new NodeMemberDataBuilder())
				->addFilter(
					new NodeMemberFilter(
						entityType: $memberEntityType,
						nodeFilter: $nodeFilter,
					),
				)
				->addStructureRole($structureRole)
		;

		if ($limit)
		{
			$nodeMemberDataBuilder->setLimit($limit);
		}

		if ($offset)
		{
			$nodeMemberDataBuilder->setOffset($offset);
		}

		if (!$ascendingSort)
		{
			$nodeMemberDataBuilder->setSort(new NodeMemberSort(id: SortDirection::Desc));
		}

		$nodeMemberCollection = $nodeMemberDataBuilder->getAll();
		$cacheManager->setData($cacheKey, ['nodeMembersArray' => $nodeMemberCollection->getValues()], $cacheDir);

		return $nodeMemberCollection;
	}

	private function getStructureRoleByRoleItem(?Role $role): ?StructureRole
	{
		return match ($role?->xmlId)
		{
			NodeMember::DEFAULT_ROLE_XML_ID['HEAD'] => StructureRole::HEAD,
			NodeMember::DEFAULT_ROLE_XML_ID['DEPUTY_HEAD'] => StructureRole::DEPUTY_HEAD,
			NodeMember::DEFAULT_ROLE_XML_ID['EMPLOYEE'] => StructureRole::EMPLOYEE,
			NodeMember::TEAM_ROLE_XML_ID['TEAM_HEAD'] => StructureRole::TEAM_HEAD,
			NodeMember::TEAM_ROLE_XML_ID['TEAM_DEPUTY_HEAD'] => StructureRole::TEAM_DEPUTY_HEAD,
			NodeMember::TEAM_ROLE_XML_ID['TEAM_EMPLOYEE'] => StructureRole::TEAM_EMPLOYEE,
			default => null,
		};
	}

	/**
	 * Get nodes that connect two users with the condition that the first user's node must not be lower
	 * in structure's hierarchy than the second user's node
	 *
	 * @param int $parentUserId - id of a user that have to be in a higher node than $childUserId.
	 * $parentUserId node must be connected to $childUserId node either directly or through a chain of child nodes
	 * @param int $childUserId - id of a user that have to be in a lower node than $childUserId
	 * @return array Array of arrays with keys:
	 * - MEMBER_1_ID: ID of the parent user's node member record
	 * - MEMBER_1_ROLE_ID: Role ID of the parent user in their node
	 * - PARENT_ID: Parent node ID in the path
	 * - CHILD_ID: Child node ID in the path
	 * - DEPTH: Depth level in the node hierarchy
	 * - MEMBER_2_ID: ID of the child user's node member record
	 * - MEMBER_2_ROLE_ID: Role ID of the child user in their node
	 * @throws \Bitrix\Main\DB\SqlQueryException
	 */
	public function getConnectedNodePathsForUsers(int $parentUserId, int $childUserId): array
	{
		$connection = Application::getConnection();

		// Query logic:
		// 1. select all node paths of the first user
		// 2. select all nodes for the second user
		// 3. for both selections get the role ID that the user performs in the node
		// 4. join two selections so that CHILD_ID from the first selection equals NODE_ID from the second selection
		$sql = <<<SQL
SELECT b_hr_structure_node_member.ID AS MEMBER_1_ID, 
	b_hr_structure_node_member_role.ROLE_ID AS MEMBER_1_ROLE_ID,
	b_hr_structure_node_path.PARENT_ID, 
	b_hr_structure_node_path.CHILD_ID,
	b_hr_structure_node_path.DEPTH,
	MEMBER_2.MEMBER_ID AS MEMBER_2_ID,
	MEMBER_2.ROLE_ID AS MEMBER_2_ROLE_ID
FROM b_hr_structure_node_member
JOIN b_hr_structure_node_member_role ON b_hr_structure_node_member.ID = b_hr_structure_node_member_role.MEMBER_ID
JOIN b_hr_structure_node_path ON b_hr_structure_node_path.PARENT_ID = b_hr_structure_node_member.NODE_ID
JOIN b_hr_structure_node ON b_hr_structure_node_member.NODE_ID = b_hr_structure_node.ID
JOIN (SELECT b_hr_structure_node_member.ID AS MEMBER_ID, 
		b_hr_structure_node_member_role.ROLE_ID, 
		b_hr_structure_node_member.NODE_ID, 
		b_hr_structure_node.STRUCTURE_ID
	FROM b_hr_structure_node_member
	JOIN b_hr_structure_node_member_role ON b_hr_structure_node_member.ID = b_hr_structure_node_member_role.MEMBER_ID
	JOIN b_hr_structure_node ON b_hr_structure_node_member.NODE_ID = b_hr_structure_node.ID
	WHERE b_hr_structure_node_member.ENTITY_TYPE = 'USER' AND b_hr_structure_node_member.ENTITY_ID = $childUserId
) AS MEMBER_2 ON b_hr_structure_node_path.CHILD_ID = MEMBER_2.NODE_ID
	AND MEMBER_2.STRUCTURE_ID = b_hr_structure_node.STRUCTURE_ID
WHERE b_hr_structure_node_member.ENTITY_TYPE = 'USER' AND b_hr_structure_node_member.ENTITY_ID = $parentUserId;
SQL;

		return $connection->query($sql)->fetchAll();
	}

	public function injectUserNodeSubquery(
		Query $query,
		array|NodeEntityType|null $nodeTypes = NodeEntityType::DEPARTMENT,
		?bool $active = true,
		?array $nodeIds = null,
	): Query
	{
		$query->registerRuntimeField(
			new Reference(
				'USER_NODE_MEMBER',
				\Bitrix\HumanResources\Model\NodeMember::class,
				Join::on('this.ID', 'ref.ENTITY_ID')
					->where('ref.ENTITY_TYPE', MemberEntityType::USER->value),
				['join_type' => 'INNER'],
			)
		);

		if (!empty($nodeTypes))
		{
			if (is_array($nodeTypes))
			{
				$query->whereIn(
					'USER_NODE_MEMBER.NODE.TYPE',
					array_map(fn(NodeEntityType $type) => $type->value, $nodeTypes),
				);
			}
			else
			{
				$query->where('USER_NODE_MEMBER.NODE.TYPE', $nodeTypes->value);
			}
		}

		if (!empty($nodeIds))
		{
			$query->whereIn('USER_NODE_MEMBER.NODE_ID', $nodeIds);
		}

		if ($active !== null)
		{
			$query->where('USER_NODE_MEMBER.ACTIVE', $active ? 'Y' : 'N');
		}

		return $query;
	}
}
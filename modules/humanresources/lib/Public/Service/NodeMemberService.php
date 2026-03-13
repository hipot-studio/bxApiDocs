<?php

namespace Bitrix\HumanResources\Public\Service;

use Bitrix\HumanResources\Enum\NodeActiveFilter;
use Bitrix\HumanResources\Internals\Service\Container as InternalContainer;
use Bitrix\HumanResources\Item\Collection\NodeMemberCollection;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\StructureAction;
use Bitrix\Main\ORM\Query\Query;

final class NodeMemberService
{
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
		return InternalContainer::getNodeMemberRepository()->findAllByEntityIds(
			entityIds: $entityIds,
			memberEntityType: $memberEntityType,
			nodeIds: $nodeIds,
			nodeTypes: $nodeTypes,
			structureId: $structureId,
			structureAction: $structureAction,
			nodeActiveFilter: $nodeActiveFilter,
		);
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
		return InternalContainer::getNodeMemberRepository()->findAllByRoleIdAndNodeId(
			$roleId,
			$nodeId,
			$memberEntityType,
			$limit,
			$offset,
			$ascendingSort,
		);
	}

	/**
	 * Inject a subquery for UserTable
	 * It joins b_hr_structure_node_member, so keep in mind a possibility of duplication
	 *
	 * @param Query $query
	 * @param array<NodeEntityType>|NodeEntityType|null $nodeTypes
	 * @param bool|null $active
	 * @param array|null $nodeIds
	 * @return Query
	 */
	public function injectUserNodeSubquery(
		Query $query,
		array|NodeEntityType|null $nodeTypes = NodeEntityType::DEPARTMENT,
		?bool $active = true,
		?array $nodeIds = null,
	): Query
	{
		return InternalContainer::getNodeMemberRepository()->injectUserNodeSubquery(
			$query,
			$nodeTypes,
			$active,
			$nodeIds,
		);
	}
}

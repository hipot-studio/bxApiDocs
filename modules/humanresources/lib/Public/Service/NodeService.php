<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Public\Service;

use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Enum\NodeActiveFilter;
use Bitrix\HumanResources\Internals\Service\Container as InternalContainer;
use Bitrix\HumanResources\Item\Collection\NodeCollection;
use Bitrix\HumanResources\Item\Node;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\StructureAction;
use Bitrix\HumanResources\Util\StructureHelper;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;

/**
 * Public service for common node operations.
 *
 * @see \Bitrix\HumanResources\Public\Service\Container::getNodeService()
 */
final class NodeService
{
	//region single node getters
	/**
	 * @param int $nodeId
	 * @param bool $needDepth
	 * @param StructureAction|null $structureAction
	 *
	 * @return Node|null
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getById(
		int $nodeId,
		bool $needDepth = false,
		?StructureAction $structureAction = null,
	): ?Node
	{
		if ($needDepth && !$structureAction)
		{
			return InternalContainer::getNodeRepository()->getByIdWithDepth($nodeId);
		}

		if (!$needDepth && !$structureAction)
		{
			return InternalContainer::getNodeRepository()->getById($nodeId);
		}

		$node = InternalContainer::getNodeRepository()->getById($nodeId, $structureAction);
		if (!$node || !$needDepth)
		{
			return $node;
		}

		return InternalContainer::getNodeRepository()->getByIdWithDepth($nodeId);
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	public function getByAccessCode(
		string $accessCode,
		?NodeEntityType $nodeType = null,
		?StructureAction $structureAction = null,
	): ?Node
	{
		if (!$structureAction)
		{
			return InternalContainer::getNodeRepository()->getByAccessCode($accessCode);
		}

		$nodeIds = $this->getNodeIdsByAccessCodes([$accessCode]);
		if (count($nodeIds) !== 1)
		{
			return null;
		}

		return InternalContainer::getNodeRepository()->getById($nodeIds[0], $nodeType, $structureAction);
	}

	public function getRootNode(?int $structureId = null): ?Node
	{
		$structureId ??= StructureHelper::getDefaultStructure()?->id;
		if (!$structureId)
		{
			return null;
		}

		return InternalContainer::getNodeRepository()->getRootNodeByStructureId($structureId);
	}
	//endregion

	//region node collection getters
	public function findAll(
		int $structureId,
		array $nodeTypes = [NodeEntityType::DEPARTMENT],
		?StructureAction $structureAction = null,
		NodeActiveFilter $activeFilter = NodeActiveFilter::ONLY_GLOBAL_ACTIVE,
		int $limit = 100,
		int $offset = 0,
	): NodeCollection
	{
		return InternalContainer::getNodeRepository()->findAll(
			nodeTypes: $nodeTypes,
			structureId: $structureId,
			structureAction: $structureAction,
			activeFilter: $activeFilter,
			limit: $limit,
			offset: $offset,
		);
	}

	public function findAllByIds(
		array $nodeIds,
		?int $structureId = null,
		array $nodeTypes = [NodeEntityType::DEPARTMENT],
		?StructureAction $structureAction = null,
		NodeActiveFilter $activeFilter = NodeActiveFilter::ONLY_GLOBAL_ACTIVE,
	): NodeCollection
	{
		if (empty($nodeIds))
		{
			return new NodeCollection();
		}

		return InternalContainer::getNodeRepository()->findAllByIds(
			nodeIds: $nodeIds,
			structureId: $structureId,
			nodeTypes: $nodeTypes,
			structureAction: $structureAction,
			activeFilter: $activeFilter,
		);
	}

	public function findParentsByNodeId(
		int $nodeId,
		?array $nodeTypes = null,
		DepthLevel|int $depthLevel = DepthLevel::FIRST,
		?StructureAction $structureAction = null,
	): ?NodeCollection
	{
		if ($nodeTypes === null)
		{
			$node = $this->getById($nodeId);
			if (!$node)
			{
				return new NodeCollection();
			}

			$nodeTypes = $this->getNodeTypeFilter($node->type);
		}

		return InternalContainer::getNodeRepository()->findParentsOfNode(
			nodeId: $nodeId,
			nodeTypes: $nodeTypes,
			depthLevel: $depthLevel,
			structureAction: $structureAction,
		);
	}

	public function findChildrenByNodeIds(
		array $nodeIds,
		?int $structureId = null,
		array $nodeTypes = [NodeEntityType::DEPARTMENT, NodeEntityType::TEAM],
		DepthLevel|int $depthLevel = DepthLevel::FIRST,
		?StructureAction $structureAction = null,
		NodeActiveFilter $activeFilter = NodeActiveFilter::ONLY_GLOBAL_ACTIVE,
	): NodeCollection
	{
		return InternalContainer::getNodeRepository()->findChildrenByNodeIds(
			nodeIds: $nodeIds,
			structureId: $structureId,
			depthLevel: $depthLevel,
			nodeTypes: $nodeTypes,
			structureAction: $structureAction,
			activeFilter: $activeFilter,
		);
	}

	public function findAllByMemberEntityId(
		int $memberEntityId,
		MemberEntityType $memberEntityType = MemberEntityType::USER,
		?int $structureId = null,
		array $nodeTypes = [NodeEntityType::DEPARTMENT],
		?StructureAction $structureAction = null,
		NodeActiveFilter $nodeActiveFilter = NodeActiveFilter::ONLY_GLOBAL_ACTIVE,
	): NodeCollection
	{
		return InternalContainer::getNodeRepository()->findAllByMemberEntityId(
			entityId: $memberEntityId,
			memberEntityType: $memberEntityType,
			structureId: $structureId,
			nodeTypes: $nodeTypes,
			structureAction: $structureAction,
			activeFilter: $nodeActiveFilter,
		);
	}

	public function findAllByAccessCodes(
		array $accessCodes,
		?int $structureId = null,
		?array $nodeTypes = null,
		?StructureAction $structureAction = null,
		NodeActiveFilter $activeFilter = NodeActiveFilter::ONLY_GLOBAL_ACTIVE,
	): NodeCollection
	{
		if (empty($accessCodes))
		{
			return new NodeCollection();
		}

		$nodeIds = $this->getNodeIdsByAccessCodes($accessCodes);
		if (empty($nodeIds))
		{
			return new NodeCollection();
		}

		return InternalContainer::getNodeRepository()->findAllByIds(
			nodeIds: $nodeIds,
			structureId: $structureId,
			nodeTypes: $nodeTypes ?? [NodeEntityType::DEPARTMENT],
			structureAction: $structureAction,
			activeFilter: $activeFilter,
		);
	}

	public function findAllByName(
		?string $name,
		?int $structureId = null,
		?array $parentIds = null,
		array $nodeTypes = [NodeEntityType::DEPARTMENT],
		DepthLevel|int $depthLevel = DepthLevel::FULL,
		bool $strict = false,
		?StructureAction $structureAction = null,
		NodeActiveFilter $activeFilter = NodeActiveFilter::ONLY_GLOBAL_ACTIVE,
		?int $limit = 100,
	): NodeCollection
	{
		if ($name)
		{
			return InternalContainer::getNodeRepository()->findAllByName(
				name: $name,
				strict: $strict,
				parentIds: $parentIds,
				nodeTypes: $nodeTypes,
				structureId: $structureId,
				depthLevel: $depthLevel,
				structureAction: $structureAction,
				activeFilter: $activeFilter,
				limit: $limit,
			);
		}

		if (!empty($parentIds))
		{
			return InternalContainer::getNodeRepository()->findChildrenByNodeIds(
				nodeIds: $parentIds,
				structureId: $structureId,
				depthLevel: $depthLevel,
				nodeTypes: $nodeTypes,
				structureAction: $structureAction,
				activeFilter: $activeFilter,
			);
		}

		return InternalContainer::getNodeRepository()->findAll(
			nodeTypes: $nodeTypes,
			structureId: $structureId,
			structureAction: $structureAction,
			activeFilter: $activeFilter,
			limit: $limit ?? 100,
		);
	}

	public function findAllByXmlId(
		string $xmlId,
		int $structureId = null,
		?StructureAction $structureAction = null,
		NodeActiveFilter $activeFilter = NodeActiveFilter::ONLY_GLOBAL_ACTIVE
	): NodeCollection
	{
		$structureId ??= StructureHelper::getDefaultStructure()?->id;
		$nodeCollection = InternalContainer::getNodeRepository()->findAllByXmlId(
			$xmlId,
			$structureId,
			$activeFilter,
		);

		if (!$structureAction || $nodeCollection->empty())
		{
			return $nodeCollection;
		}

		return InternalContainer::getNodeRepository()->findAllByIds(
			nodeIds: $nodeCollection->getIds(),
			structureId: $structureId,
			structureAction: $structureAction,
			activeFilter: $activeFilter,
		);
	}
	//endregion

	//region utils
	public function getNodeIdsByAccessCodes(array $accessCodes): array
	{
		if (empty($accessCodes))
		{
			return [];
		}

		return InternalContainer::getNodeAccessCodeService()->getNodeIdsByAccessCodes($accessCodes);
	}
	//endregion

	//region private methods
	private function getNodeTypeFilter(NodeEntityType $targetNodeType): array
	{
		return match ($targetNodeType)
		{
			NodeEntityType::TEAM => [NodeEntityType::DEPARTMENT, NodeEntityType::TEAM],
			default => [$targetNodeType],
		};
	}
	//endregion
}

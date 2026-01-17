<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Internals\Repository\Structure\Node;

use Bitrix\HumanResources\Builder\Structure\Filter\Column\IdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\Node\NodeTypeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeFilter;
use Bitrix\HumanResources\Builder\Structure\NodeDataBuilder;
use Bitrix\HumanResources\Builder\Structure\Sort\NodeSort;
use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Enum\SortDirection;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Item;

final class NodeRepository
{
	/**
	 * @param int $structureId
	 *
	 * @return array<array-key, int> A map of structure nodes where the key is the node ID and the value is the parent node ID or null
	 */
	public static function getStructuresNodeMap(int $structureId): array
	{
		$map = [];

		$nodeCollection =
			(new NodeDataBuilder())
				->setSelect(['ID', 'PARENT_ID', 'TYPE'])
				->addFilter(
					new NodeFilter(
						entityTypeFilter: NodeTypeFilter::fromNodeTypes([NodeEntityType::DEPARTMENT, NodeEntityType::TEAM]),
						structureId: $structureId,
						depthLevel: DepthLevel::FULL,
					),
				)
				->getAll()
		;

		foreach ($nodeCollection as $node)
		{
			$map[$node->id] = [
				'parentId' => (int)$node->parentId,
				'entityType' => $node->type ?? '',
			];
		}

		return $map;
	}

	public static function getChildrenOfNode(
		Item\Node $node,
		DepthLevel|int $depthLevel = DepthLevel::FIRST,
		array $entityTypes = [NodeEntityType::DEPARTMENT, NodeEntityType::TEAM],
	): Item\Collection\NodeCollection
	{
		return (new NodeDataBuilder())
			->addFilter(
				new NodeFilter(
					idFilter: IdFilter::fromId($node->id),
					entityTypeFilter: NodeTypeFilter::fromNodeTypes($entityTypes),
					depthLevel: $depthLevel,
				),
			)
			->setSort(new NodeSort(sort: SortDirection::Asc))
			->getAll()
		;
	}
}
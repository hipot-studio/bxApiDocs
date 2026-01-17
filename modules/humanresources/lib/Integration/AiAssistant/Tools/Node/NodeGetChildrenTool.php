<?php

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Node;

use Bitrix\HumanResources\Access\Model\NodeModel;
use Bitrix\HumanResources\Access\StructureActionDictionary;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\IdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\Node\NodeTypeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\SelectionCondition\Node\NodeAccessFilter;
use Bitrix\HumanResources\Builder\Structure\NodeDataBuilder;
use Bitrix\HumanResources\Builder\Structure\Sort\NodeSort;
use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Enum\Direction;
use Bitrix\HumanResources\Enum\SortDirection;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\NodeBaseTool;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\StructureAction;
use Bitrix\HumanResources\Util\StructureHelper;

abstract class NodeGetChildrenTool extends NodeBaseTool
{
	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'nodeId' => [
					'description' => 'Identifier of the node to get children of',
					'type' => 'number',
				],
			],
			'additionalProperties' => false,
			'required' => ['nodeId'],
		];
	}

	public function execute(int $userId, ...$args): string
	{
		$item = NodeModel::createFromId((int)$args['nodeId']);

		$actionId = $this->type === NodeEntityType::DEPARTMENT
			? StructureActionDictionary::ACTION_STRUCTURE_VIEW
			: StructureActionDictionary::ACTION_TEAM_VIEW
		;
		if (!$this->checkAccess($userId, $actionId, $item))
		{
			return 'Access denied';
		}

		$parentNode = $item->getNode();

		try
		{
			$nodes =
				(new NodeDataBuilder())
					->addFilter(
						new NodeFilter(
							idFilter: IdFilter::fromId($parentNode->id),
							entityTypeFilter: NodeTypeFilter::fromNodeTypes([NodeEntityType::DEPARTMENT, NodeEntityType::TEAM]),
							structureId: $parentNode->structureId,
							direction: Direction::CHILD,
							depthLevel: DepthLevel::FIRST,
							active: true,
							accessFilter: new NodeAccessFilter(StructureAction::ViewAction, $userId),
						),
					)
					->setSort(new NodeSort(sort: SortDirection::Asc))
					->getAll()
			;

			$result = [];
			foreach ($nodes as $node)
			{
				$result[] = StructureHelper::getNodeInfo($node);
			}

			return "The node has " . count($result) . " children: \n" .
				implode(
					"\n",
					array_map(
						fn($node) => "Id: {$node['id']}, Name: {$node['name']}, Description: {$node['description']}, Type: {$node['entityType']}",
						$result,
					),
				) . '.'
			;
		}
		catch (\Exception $e)
		{
			$this->logException('Error getting children: ' . $e->getMessage());

			return 'Error getting children.';
		}
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Node;

use Bitrix\HumanResources\Access\Model\NodeModel;
use Bitrix\HumanResources\Access\StructureActionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\NodeBaseTool;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\NodeEntityType;

abstract class NodeChangeParentTool extends NodeBaseTool
{
	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'nodeId' => [
					'description' => 'Identifier of the node which name or description should be updated',
					'type' => 'number',
				],
				'parentId' => [
					'description' => 'Parent node identifier. Should not be null or 0',
					'type' => 'number',
				],
			],
			'additionalProperties' => false,
			'required' => ['nodeId', 'parentId'],
		];
	}

	public function execute(int $userId, ...$args): string
	{
		$item = NodeModel::createFromId((int)$args['nodeId']);
		$item->setTargetNodeId((int)$args['parentId']);

		$actionId = $this->type === NodeEntityType::DEPARTMENT
			? StructureActionDictionary::ACTION_DEPARTMENT_EDIT
			: StructureActionDictionary::ACTION_TEAM_EDIT
		;
		if (!$this->checkAccess($userId, $actionId, $item))
		{
			return 'Access denied';
		}

		$node = $item->getNode();

		$parentId = (int)$args['parentId'];
		if ($parentId <= 0)
		{
			return 'Error updating node: invalid parentId';
		}
		$node->parentId = $parentId;

		try
		{
			Container::getNodeService()->updateNode($node);
		}
		catch (\Exception $e)
		{
			$this->logException('Error updating node: ' . $e->getMessage());

			return 'Error updating node.';
		}

		return 'Node parent successfully updated';
	}
}

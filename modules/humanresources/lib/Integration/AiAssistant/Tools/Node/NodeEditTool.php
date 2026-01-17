<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Node;

use Bitrix\HumanResources\Access\Model\NodeModel;
use Bitrix\HumanResources\Access\StructureActionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\NodeBaseTool;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\NodeEntityType;

abstract class NodeEditTool extends NodeBaseTool
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
				'name' => [
					'description' => 'New node name. Must not be an empty string',
					'type' => 'string',
				],
				'description' => [
					'description' => 'New node description. Must not be an empty string',
					'type' => 'string',
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
			? StructureActionDictionary::ACTION_DEPARTMENT_EDIT
			: StructureActionDictionary::ACTION_TEAM_EDIT
		;
		if (!$this->checkAccess($userId, $actionId, $item))
		{
			return 'Access denied';
		}

		$node = $item->getNode();

		if ($args['name'] !== null)
		{
			$node->name = $args['name'];
		}
		if ($args['description'] !== null)
		{
			$node->description = $args['description'];
		}

		try
		{
			Container::getNodeService()->updateNode($node);
		}
		catch (\Exception $e)
		{
			$this->logException('Error updating the node: ' . $e->getMessage());

			return 'Error updating the node.';
		}

		return 'Node name and/or description updated successfully';
	}
}

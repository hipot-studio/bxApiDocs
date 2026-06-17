<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Node;

use Bitrix\HumanResources\Access\Model\NodeModel;
use Bitrix\HumanResources\Access\StructureActionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\NodeBaseTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Schema\InputProperty;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\NodeEntityType;

/**
 * Edit node properties (name, description, color).
 *
 * @see \Bitrix\HumanResources\Rest\Controller\Node::editAction — REST analog
 * @see \Bitrix\HumanResources\Controller\Structure\Node::updateAction — ajax controller
 */
abstract class NodeEditTool extends NodeBaseTool
{
	public function getInputSchema(): array
	{
		$properties = [
			'nodeId' => InputProperty::nodeId('Identifier of the node which name or description should be updated'),
			'name' => [
				'description' => 'New node name',
				'type' => 'string',
				'minLength' => 1,
			],
			'description' => [
				'description' => 'New node description',
				'type' => 'string',
				'minLength' => 1,
			],
		];

		if ($this->type === NodeEntityType::TEAM)
		{
			$properties['colorName'] = InputProperty::colorName();
		}

		return [
			'type' => 'object',
			'properties' => $properties,
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

		if (isset($args['name']))
		{
			$node->name = $args['name'];
		}
		if (isset($args['description']))
		{
			$node->description = $args['description'];
		}
		if (isset($args['colorName']))
		{
			$node->colorName = $args['colorName'];
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

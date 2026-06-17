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
 * Move node to a new parent.
 *
 * @see \Bitrix\HumanResources\Rest\Controller\Node::moveAction — REST analog
 * @see \Bitrix\HumanResources\Controller\Structure\Node::updateAction — ajax controller (parentId parameter)
 */
abstract class NodeChangeParentTool extends NodeBaseTool
{
	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'nodeId' => InputProperty::nodeId('Identifier of the node whose parent should be changed'),
				'parentId' => InputProperty::nodeId('New parent node identifier'),
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

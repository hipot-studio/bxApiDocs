<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Node;

use Bitrix\HumanResources\Access\Model\NodeModel;
use Bitrix\HumanResources\Access\StructureActionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\NodeBaseTool;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\NodeEntityType;

abstract class NodeMoveEmployeesTool extends NodeBaseTool
{
	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'nodeId' => [
					'description' => 'Identifier of the target node node where employees should be moved to',
					'type' => 'number',
				],
				'userIds' => [
					'description' => 'Array of user IDs',
					'type' => 'array',
					'items' => [
						'type' => 'number',
					]
				],
			],
			'additionalProperties' => false,
			'required' => ['nodeId', 'userIds'],
		];
	}

	public function execute(int $userId, ...$args): string
	{
		$targetItem = NodeModel::createFromId((int)$args['nodeId']);

		$actionId = $this->type === NodeEntityType::DEPARTMENT
			? StructureActionDictionary::ACTION_EMPLOYEE_ADD_TO_DEPARTMENT
			: StructureActionDictionary::ACTION_TEAM_MEMBER_ADD
		;
		if (!$this->checkAccess($userId, $actionId, $targetItem))
		{
			return 'Access denied for target node';
		}

		$targetNode = $targetItem->getNode();
		$userIds = $args['userIds'] ?? [];

		try
		{
			Container::getNodeMemberService()->moveUsersToDepartment($targetNode, $userIds);

			return 'Employees successfully moved to the target node';
		}
		catch (\Exception $e)
		{
			$this->logException('Error moving employees: ' . $e->getMessage());

			return 'Error moving employees.';
		}
	}
}

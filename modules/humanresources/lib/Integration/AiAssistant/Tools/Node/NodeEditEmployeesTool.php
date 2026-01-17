<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Node;

use Bitrix\HumanResources\Access\Model\NodeModel;
use Bitrix\HumanResources\Access\StructureActionDictionary;
use Bitrix\HumanResources\Exception\DeleteFailedException;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\NodeBaseTool;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\NodeEntityType;

abstract class NodeEditEmployeesTool extends NodeBaseTool
{
	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'nodeId' => [
					'description' => 'Identifier of the node where employees should be updated',
					'type' => 'number',
				],
				'userIds' => [
					'description' => 'Array of user IDs grouped by roles. For example: {"MEMBER_HEAD":[1,2], "MEMBER_EMPLOYEE":[3,4,5], "MEMBER_DEPUTY_HEAD":[6]}',
					'type' => 'object',
					'additionalProperties' => [
						'type' => 'array',
						'items' => [
							'type' => 'number',
						]
					]
				],
			],
			'additionalProperties' => false,
			'required' => ['nodeId', 'userIds'],
		];
	}

	public function execute(int $userId, ...$args): string
	{
		$item = NodeModel::createFromId((int)$args['nodeId']);

		$actionId = $this->type === NodeEntityType::DEPARTMENT
			? StructureActionDictionary::ACTION_EMPLOYEE_ADD_TO_DEPARTMENT
			: StructureActionDictionary::ACTION_TEAM_MEMBER_ADD
		;
		if (!$this->checkAccess($userId, $actionId, $item))
		{
			return 'Access denied';
		}

		$node = $item->getNode();
		$userIds = $args['userIds'] ?? [];

		try
		{
			Container::getNodeMemberService()->saveUsersToDepartment($node, $userIds);

			return 'Node employees updated successfully';
		}
		catch (DeleteFailedException $e)
		{
			$this->logException('Error deleting employees: ' . $e->getMessage());

			return 'Error deleting employees.';
		}
		catch (\Exception $e)
		{
			$this->logException('Error updating node employees: ' . $e->getMessage());

			return 'Error updating node employees.';
		}
	}
}

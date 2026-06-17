<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Node;

use Bitrix\HumanResources\Access\Model\NodeModel;
use Bitrix\HumanResources\Access\StructureActionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\NodeBaseTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Schema\InputProperty;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\NodeMemberRole;

/**
 * Move employees to a node.
 *
 * @see \Bitrix\HumanResources\Rest\Controller\Node\Member::moveAction — REST analog
 * @see \Bitrix\HumanResources\Controller\Structure\Node\Member::moveUserAction — ajax controller
 */
abstract class NodeMoveEmployeesTool extends NodeBaseTool
{
	public function getInputSchema(): array
	{
		$allowedRoles = NodeMemberRole::allowedValuesForNodeType($this->type);
		$defaultRole = NodeMemberRole::defaultForNodeType($this->type)->value;

		return [
			'type' => 'object',
			'properties' => [
				'nodeId' => InputProperty::nodeId('Identifier of the target node where employees should be moved to'),
				'userIds' => InputProperty::userIdList('User IDs to move to the target node') + ['minItems' => 1],
				'role' => [
					'description' => "Role XML ID applied to every moved user. Default: {$defaultRole}.",
					'type' => 'string',
					'enum' => $allowedRoles,
					'default' => $defaultRole,
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

		$roleXmlId = $args['role'] ?? NodeMemberRole::defaultForNodeType($this->type)->value;
		$allowedRoles = NodeMemberRole::allowedValuesForNodeType($this->type);
		if (!in_array($roleXmlId, $allowedRoles, true))
		{
			return 'Invalid role: ' . $roleXmlId . '. Allowed roles: ' . implode(', ', $allowedRoles) . '.';
		}

		$departmentUserIds = [
			$roleXmlId => $userIds,
		];

		try
		{
			Container::getNodeMemberService()->moveUsersToDepartment($targetNode, $departmentUserIds);

			return 'Employees successfully moved to the target node';
		}
		catch (\Exception $e)
		{
			$this->logException('Error moving employees: ' . $e->getMessage());

			return 'Error moving employees.';
		}
	}
}

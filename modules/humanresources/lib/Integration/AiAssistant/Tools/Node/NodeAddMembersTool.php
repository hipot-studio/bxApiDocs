<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Node;

use Bitrix\HumanResources\Access\Model\NodeModel;
use Bitrix\HumanResources\Access\StructureActionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\NodeBaseTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Schema\InputProperty;
use Bitrix\HumanResources\Internals\Service\Container as InternalContainer;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\NodeMemberRole;

/**
 * Add users to a node or update their role if they are already members.
 * Unlike {@see NodeEditEmployeesTool}, this tool never removes members that are
 * not mentioned in the input — it only adds new ones or changes the role of
 * existing members.
 *
 * @see \Bitrix\HumanResources\Controller\Structure\Node\Member::addUserMemberAction
 */
abstract class NodeAddMembersTool extends NodeBaseTool
{
	public function getInputSchema(): array
	{
		$allowedRoles = NodeMemberRole::allowedValuesForNodeType($this->type);

		return [
			'type' => 'object',
			'properties' => [
				'nodeId' => InputProperty::nodeId('Identifier of the node to add members to'),
				'userIds' => InputProperty::userIdList('List of user IDs to add or re-role') + ['minItems' => 1],
				'roleXmlId' => [
					'description' => 'Role XML ID to assign to all listed users',
					'type' => 'string',
					'enum' => $allowedRoles,
				],
			],
			'additionalProperties' => false,
			'required' => ['nodeId', 'userIds', 'roleXmlId'],
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
		$roleXmlId = (string)($args['roleXmlId'] ?? '');

		$allowedRoles = NodeMemberRole::allowedValuesForNodeType($this->type);
		if (!in_array($roleXmlId, $allowedRoles, true))
		{
			return 'Invalid role XML ID: ' . $roleXmlId
				. '. Allowed roles: ' . implode(', ', $allowedRoles) . '.'
			;
		}

		$role = Container::getRoleRepository()->findByXmlId($roleXmlId);
		if ($role === null)
		{
			return 'Role "' . $roleXmlId . '" not found.';
		}

		try
		{
			$result = InternalContainer::getNodeMemberService()->addOrUpdateUsersInNode(
				$node,
				$userIds,
				$role,
			);

			if ($result->empty())
			{
				return 'No members were added or updated (no valid user IDs).';
			}

			return 'Members added or updated successfully: ' . $result->count() . '.';
		}
		catch (\Exception $e)
		{
			$this->logException('Error adding members to node: ' . $e->getMessage());

			return 'Error adding members to node.';
		}
	}
}

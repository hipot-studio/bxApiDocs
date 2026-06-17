<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Node;

use Bitrix\HumanResources\Access\Model\NodeModel;
use Bitrix\HumanResources\Access\StructureActionDictionary;
use Bitrix\HumanResources\Exception\DeleteFailedException;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\NodeBaseTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Schema\InputProperty;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\NodeMemberRole;

/**
 * Add/update employees in a node by roles (save without moving from other nodes).
 *
 * @see \Bitrix\HumanResources\Rest\Controller\Node\Member::setAction — REST analog
 * @see \Bitrix\HumanResources\Controller\Structure\Node\Member::saveUserListAction — ajax controller
 */
abstract class NodeEditEmployeesTool extends NodeBaseTool
{
	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'nodeId' => InputProperty::nodeId('Identifier of the node whose member list should be replaced'),
				'userIds' => InputProperty::userIdsByRole($this->type),
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

		$allowedRoles = NodeMemberRole::allowedValuesForNodeType($this->type);
		$invalidRoles = array_diff(array_keys($userIds), $allowedRoles);
		if (!empty($invalidRoles))
		{
			return 'Invalid role XML IDs: ' . implode(', ', $invalidRoles)
				. '. Allowed roles: ' . implode(', ', $allowedRoles) . '.'
			;
		}

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

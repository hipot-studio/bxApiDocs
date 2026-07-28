<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Rest\Controller\Node;

use Bitrix\HumanResources\Access\Model\NodeModel;
use Bitrix\HumanResources\Rest\Trait\NodeControllerTrait;
use Bitrix\HumanResources\Access\StructureActionDictionary;
use Bitrix\HumanResources\Rest\Dto\NodeMemberDto;
use Bitrix\HumanResources\Rest\RequestParams;
use Bitrix\HumanResources\Internals\Service\Container as InternalContainer;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\NodeMemberRole;
use Bitrix\Main\Error;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;

/**
 * Node member operations: move, remove, set employees.
 *
 * @see \Bitrix\HumanResources\Controller\Structure\Node\Member — existing ajax controller
 */
#[DtoType(NodeMemberDto::class)]
class Member extends RestController
{
	use NodeControllerTrait;

	protected function init(): void
	{
		parent::init();
		$this->initNodeContext();
	}

	/**
	 * Move employees to a node.
	 *
	 * @restMethod humanresources.node.member.move
	 * @see \Bitrix\HumanResources\Controller\Structure\Node\Member::moveUserAction
	 * @see \Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeMoveEmployeesTool
	 *
	 * Request params:
	 * - int    nodeId   Target node identifier (required)
	 * - int[]  userIds  Array of user IDs to move (required)
	 * - string role     Role XML ID for moved employees (optional, defaults to MEMBER_EMPLOYEE / MEMBER_TEAM_EMPLOYEE)
	 *                   Department roles: MEMBER_HEAD, MEMBER_DEPUTY_HEAD, MEMBER_EMPLOYEE
	 *                   Team roles: MEMBER_TEAM_HEAD, MEMBER_TEAM_DEPUTY_HEAD, MEMBER_TEAM_EMPLOYEE
	 */
	public function moveAction(): ArrayResponse
	{
		$params = new RequestParams($this->getRequest()->getJsonList());

		$nodeId = $params->requireInt('nodeId');
		$userIds = $params->requireArray('userIds');
		$node = $this->getNodeWithAccessCheck(
			$nodeId,
			StructureActionDictionary::ACTION_EMPLOYEE_ADD_TO_DEPARTMENT,
			StructureActionDictionary::ACTION_TEAM_MEMBER_ADD,
		);

		$roleXmlId = $params->getString('role', NodeMemberRole::defaultForNodeType($node->type)->value);
		$allowedRoles = NodeMemberRole::allowedValuesForNodeType($node->type);

		if (!in_array($roleXmlId, $allowedRoles, true))
		{
			throw new RequestValidationException([
				new Error(
					'Invalid role "' . $roleXmlId . '". Allowed: ' . implode(', ', $allowedRoles) . '.',
					'INVALID_ROLE',
				),
			]);
		}

		Container::getNodeMemberService()->moveUsersToDepartment($node, [
			$roleXmlId => $userIds,
		]);

		return new ArrayResponse(['success' => true]);
	}

	/**
	 * Remove employees from a node.
	 *
	 * @restMethod humanresources.node.member.remove
	 * @see \Bitrix\HumanResources\Controller\Structure\Node\Member::deleteUserAction
	 * @see \Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeRemoveEmployeeTool
	 *
	 * Request params:
	 * - int   nodeId   Node identifier (required)
	 * - int[] userIds  Array of user IDs to remove (required)
	 */
	public function removeAction(): ArrayResponse
	{
		$params = new RequestParams($this->getRequest()->getJsonList());

		$nodeId = $params->requireInt('nodeId');
		$userIds = $params->requireArray('userIds');
		$this->getNodeWithAccessCheck(
			$nodeId,
			StructureActionDictionary::ACTION_EMPLOYEE_REMOVE_FROM_DEPARTMENT,
			StructureActionDictionary::ACTION_TEAM_MEMBER_REMOVE,
		);

		$result = Container::getNodeMemberService()->removeFromNodeByUserIds($nodeId, $userIds);

		return new ArrayResponse($result);
	}

	/**
	 * Add users to a node or change the role of existing members.
	 * Does NOT remove members that are absent from the input.
	 *
	 * @restMethod humanresources.node.member.add
	 * @see \Bitrix\HumanResources\Controller\Structure\Node\Member::addUserMemberAction
	 * @see \Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeAddMembersTool
	 *
	 * Request params:
	 * - int    nodeId   Node identifier (required)
	 * - int[]  userIds  Array of user IDs to add or re-role (required)
	 * - string role     Role XML ID applied to every user in userIds (required)
	 *                   Department roles: MEMBER_HEAD, MEMBER_DEPUTY_HEAD, MEMBER_EMPLOYEE
	 *                   Team roles: MEMBER_TEAM_HEAD, MEMBER_TEAM_DEPUTY_HEAD, MEMBER_TEAM_EMPLOYEE
	 */
	public function addAction(): ArrayResponse
	{
		$params = new RequestParams($this->getRequest()->getJsonList());

		$nodeId = $params->requireInt('nodeId');
		$userIds = $params->requireArray('userIds');
		$roleXmlId = $params->requireString('role');
		$node = $this->getNodeWithAccessCheck(
			$nodeId,
			StructureActionDictionary::ACTION_EMPLOYEE_ADD_TO_DEPARTMENT,
			StructureActionDictionary::ACTION_TEAM_MEMBER_ADD,
		);

		$allowedRoles = NodeMemberRole::allowedValuesForNodeType($node->type);
		if (!in_array($roleXmlId, $allowedRoles, true))
		{
			throw new RequestValidationException([
				new Error(
					'Invalid role "' . $roleXmlId . '". Allowed: ' . implode(', ', $allowedRoles) . '.',
					'INVALID_ROLE',
				),
			]);
		}

		$role = Container::getRoleRepository()->findByXmlId($roleXmlId);
		if ($role === null)
		{
			throw new RequestValidationException([
				new Error('Role "' . $roleXmlId . '" not found.', 'ROLE_NOT_FOUND'),
			]);
		}

		InternalContainer::getNodeMemberService()->addOrUpdateUsersInNode($node, $userIds, $role);

		return new ArrayResponse(['success' => true]);
	}

	/**
	 * Add/update employees in a node by roles (save without moving from other nodes).
	 *
	 * @restMethod humanresources.node.member.set
	 * @see \Bitrix\HumanResources\Controller\Structure\Node\Member::saveUserListAction
	 * @see \Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeEditEmployeesTool
	 *
	 * Request params:
	 * - int    nodeId   Node identifier (required)
	 * - object userIds  Object mapping role XML IDs to arrays of user IDs (required)
	 *                   Department roles: MEMBER_HEAD, MEMBER_DEPUTY_HEAD, MEMBER_EMPLOYEE
	 *                   Team roles: MEMBER_TEAM_HEAD, MEMBER_TEAM_DEPUTY_HEAD, MEMBER_TEAM_EMPLOYEE
	 *                   Example: {"MEMBER_HEAD": [1], "MEMBER_EMPLOYEE": [2, 3]}
	 */
	public function setAction(): ArrayResponse
	{
		$params = new RequestParams($this->getRequest()->getJsonList());

		$nodeId = $params->requireInt('nodeId');
		$userIds = $params->requireArray('userIds');
		$node = $this->getNodeWithAccessCheck(
			$nodeId,
			StructureActionDictionary::ACTION_EMPLOYEE_ADD_TO_DEPARTMENT,
			StructureActionDictionary::ACTION_TEAM_MEMBER_ADD,
		);

		$allowedRoles = NodeMemberRole::allowedValuesForNodeType($node->type);
		$invalidRoles = array_diff(array_keys($userIds), $allowedRoles);
		if (!empty($invalidRoles))
		{
			throw new RequestValidationException([
				new Error(
					'Invalid roles: ' . implode(', ', $invalidRoles) . '. Allowed: ' . implode(', ', $allowedRoles) . '.',
					'INVALID_ROLE',
				),
			]);
		}

		Container::getNodeMemberService()->saveUsersToDepartment($node, $userIds);

		return new ArrayResponse(['success' => true]);
	}

	private function getNodeWithAccessCheck(
		int $nodeId,
		string $departmentAction,
		string $teamAction,
	): \Bitrix\HumanResources\Item\Node
	{
		$node = $this->requireNodeById($nodeId);
		$actionId = $node->type === NodeEntityType::TEAM ? $teamAction : $departmentAction;

		if (!$this->accessController->check($actionId, NodeModel::createFromNode($node)))
		{
			throw new AccessDeniedException();
		}

		return $node;
	}
}

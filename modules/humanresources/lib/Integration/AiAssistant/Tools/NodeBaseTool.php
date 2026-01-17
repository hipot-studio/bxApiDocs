<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\HumanResources\Access\Model\NodeModel;
use Bitrix\HumanResources\Access\Model\UserModel;
use Bitrix\HumanResources\Access\Permission\Mapper\TeamPermissionMapper;
use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Access\Permission\PermissionHelper;
use Bitrix\HumanResources\Access\Permission\PermissionVariablesDictionary;
use Bitrix\HumanResources\Access\StructureAccessController;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\NodeEntityType;

abstract class NodeBaseTool extends ToolContract
{
	protected NodeEntityType $type = NodeEntityType::DEPARTMENT;
	protected string $permissionId = PermissionDictionary::HUMAN_RESOURCES_STRUCTURE_VIEW;

	/**
	 * Check if the user has permissions to list this tool
	 *
	 * @param int $userId
	 * @return bool
	 */
	public function canList(int $userId): bool
	{
		$user = UserModel::createFromId($userId);

		if ($user->isAdmin())
		{
			return true;
		}

		if ($this->type === NodeEntityType::TEAM)
		{
			$permissionCollection = PermissionHelper::getPermissionValue($this->permissionId, $userId);
			$teamPermissionMapper = TeamPermissionMapper::createFromCollection($permissionCollection);
			if (
				$teamPermissionMapper->getTeamPermissionValue() === PermissionVariablesDictionary::VARIABLE_NONE
				&& $teamPermissionMapper->getDepartmentPermissionValue() === PermissionVariablesDictionary::VARIABLE_NONE
			)
			{
				return false;
			}

			if ($teamPermissionMapper->getTeamPermissionValue() === PermissionVariablesDictionary::VARIABLE_ALL)
			{
				return true;
			}
		}
		else
		{
			$permissionValue = $user->getPermission($this->permissionId);
			if ($permissionValue === PermissionVariablesDictionary::VARIABLE_NONE)
			{
				return false;
			}

			if (
				$permissionValue !== PermissionVariablesDictionary::VARIABLE_SELF_DEPARTMENTS
				&& $permissionValue !== PermissionVariablesDictionary::VARIABLE_SELF_DEPARTMENTS_SUB_DEPARTMENTS
			)
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Check if the user can run this tool
	 *
	 * @param int $userId
	 * @return bool
	 */
	public function canRun(int $userId): bool
	{
		return $this->canList($userId);
	}

	/**
	 * Create an access controller for the current user
	 *
	 * @param int $userId
	 * @return StructureAccessController
	 */
	protected function createAccessController(int $userId): StructureAccessController
	{
		return new StructureAccessController($userId);
	}

	/**
	 * Check if the user has the specified action permission for the given node
	 *
	 * @param int $userId
	 * @param string $action
	 * @param NodeModel $item
	 * @return bool
	 * @throws \Bitrix\Main\Access\Exception\UnknownActionException
	 */
	protected function checkAccess(int $userId, string $action, NodeModel $item): bool
	{
		$accessController = $this->createAccessController($userId);

		return $accessController->check($action, $item);
	}

	protected function logException(string $message): void
	{
		Container::getStructureLogger()->write([
			'feature' 	=> 'Integration\AiAssistant',
			'type'		=> 'error',
			'message' 	=> $message,
		]);
	}
}

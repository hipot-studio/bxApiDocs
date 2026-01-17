<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Department;

use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeMoveEmployeesTool;
use Bitrix\HumanResources\Type\NodeEntityType;

class DepartmentMoveEmployeesTool extends NodeMoveEmployeesTool
{
	protected NodeEntityType $type = NodeEntityType::DEPARTMENT;
	protected string $permissionId = PermissionDictionary::HUMAN_RESOURCES_EMPLOYEE_REMOVE_FROM_DEPARTMENT;

	public function getName(): string
	{
		return 'move_employees';
	}

	public function getDescription(): string
	{
		return 'Move employees to another node identified by `nodeId` when node is a department. 
Use this function to move node members. Accepts a list of user IDs grouped by roles.';
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Department;

use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeRemoveEmployeeTool;
use Bitrix\HumanResources\Type\NodeEntityType;

class DepartmentRemoveEmployeeTool extends NodeRemoveEmployeeTool
{
	protected NodeEntityType $type = NodeEntityType::DEPARTMENT;
	protected string $permissionId = PermissionDictionary::HUMAN_RESOURCES_EMPLOYEE_REMOVE_FROM_DEPARTMENT;

	public function getName(): string
	{
		return 'department_remove_employee';
	}

	public function getDescription(): string
	{
		return 'Remove employees from a department. Only works if the employee belongs to more than one department. If the employee is in only one department, the removal will fail — use department_move_employees to transfer them to another department instead.';
	}
}

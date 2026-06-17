<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Department;

use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeEditEmployeesTool;
use Bitrix\HumanResources\Type\NodeEntityType;

class DepartmentEditEmployeesTool extends NodeEditEmployeesTool
{
	protected NodeEntityType $type = NodeEntityType::DEPARTMENT;
	protected string $permissionId = PermissionDictionary::HUMAN_RESOURCES_EMPLOYEE_ADD_TO_DEPARTMENT;

	public function getName(): string
	{
		return 'department_edit_employees';
	}

	public function getDescription(): string
	{
		return 'DESTRUCTIVE REPLACE. Replaces the full member list of the department. '
			. 'Members absent from the input will be removed. '
			. 'Use only when the user explicitly wants to rewrite the full composition. '
			. 'For adding or re-roling individual members use `department_add_members`; '
			. 'to bring users from another department use `department_move_employees`.';
	}
}

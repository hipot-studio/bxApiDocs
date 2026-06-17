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
		return 'department_move_employees';
	}

	public function getDescription(): string
	{
		return 'Move users to the target department. '
			. 'Side effect: each user is removed from any other department they currently belong to. '
			. 'For team membership use `team_move_employees`.';
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Department;

use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeEditTool;
use Bitrix\HumanResources\Type\NodeEntityType;

class DepartmentEditTool extends NodeEditTool
{
	protected NodeEntityType $type = NodeEntityType::DEPARTMENT;
	protected string $permissionId = PermissionDictionary::HUMAN_RESOURCES_DEPARTMENT_EDIT;

	public function getName(): string
	{
		return 'department_edit';
	}

	public function getDescription(): string
	{
		return 'Update the name and/or description of an existing department. '
			. 'Does not move the node, change members, or alter communications. '
			. 'Use `department_change_parent` to reparent and `department_edit_employees` to replace the member list.';
	}
}

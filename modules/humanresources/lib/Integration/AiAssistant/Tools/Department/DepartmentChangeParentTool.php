<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Department;

use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeChangeParentTool;
use Bitrix\HumanResources\Type\NodeEntityType;

class DepartmentChangeParentTool extends NodeChangeParentTool
{
	protected NodeEntityType $type = NodeEntityType::DEPARTMENT;
	protected string $permissionId = PermissionDictionary::HUMAN_RESOURCES_DEPARTMENT_EDIT;

	public function getName(): string
	{
		return 'department_change_parent';
	}

	public function getDescription(): string
	{
		return 'Reparent a department under a new `parentId`. '
			. 'Side effects: changes the reporting chain, business-process approval scope, and access scope for the department and its entire subtree. '
			. 'Always confirm with the user before calling.';
	}
}

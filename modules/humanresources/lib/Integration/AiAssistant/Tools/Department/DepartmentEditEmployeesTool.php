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
		return 'edit_employees';
	}

	public function getDescription(): string
	{
		return 'Edit employee list for the node identified by `nodeId` when node is a department. 
Use this function to update node members. Accepts a list of user IDs grouped by roles.';
	}
}

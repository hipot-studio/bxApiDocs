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
		return 'edit';
	}

	public function getDescription(): string
	{
		return 'Rename name to `name` and description to `description` for the node identified by `nodeId` when node is a department. 
Use this function to update node information';
	}
}

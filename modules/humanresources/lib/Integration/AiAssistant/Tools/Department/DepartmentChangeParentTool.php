<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Department;

use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use \Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeChangeParentTool;
use Bitrix\HumanResources\Type\NodeEntityType;

class DepartmentChangeParentTool extends NodeChangeParentTool
{
	protected NodeEntityType $type = NodeEntityType::DEPARTMENT;
	protected string $permissionId = PermissionDictionary::HUMAN_RESOURCES_DEPARTMENT_EDIT;

	public function getName(): string
	{
		return 'change_parent';
	}

	public function getDescription(): string
	{
		return 'Change node parent to `parentId` for the node identified by `nodeId` when node is a department. Use this function to change level of subordination';
	}
}

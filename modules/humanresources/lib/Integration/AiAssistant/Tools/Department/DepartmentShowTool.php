<?php

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Department;

use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeShowTool;
use Bitrix\HumanResources\Type\NodeEntityType;

class DepartmentShowTool extends NodeShowTool
{
	protected NodeEntityType $type = NodeEntityType::DEPARTMENT;
	protected string $permissionId = PermissionDictionary::HUMAN_RESOURCES_STRUCTURE_VIEW;

	public function getName(): string
	{
		return 'show';
	}

	public function getDescription(): string
	{
		return 'Get name, description and a list of employees for the node identified by `nodeId` when node is a department.';
	}
}

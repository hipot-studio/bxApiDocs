<?php

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Department;

use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeGetChildrenTool;
use Bitrix\HumanResources\Type\NodeEntityType;

class DepartmentGetChildrenTool extends NodeGetChildrenTool
{
	protected NodeEntityType $type = NodeEntityType::DEPARTMENT;
	protected string $permissionId = PermissionDictionary::HUMAN_RESOURCES_STRUCTURE_VIEW;

	public function getName(): string
	{
		return 'get_children';
	}

	public function getDescription(): string
	{
		return 'Get a list of child nodes of the node identified by `nodeId` when node is a department.';
	}
}

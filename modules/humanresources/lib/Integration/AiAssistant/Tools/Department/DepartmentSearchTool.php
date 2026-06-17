<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Department;

use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeSearchTool;
use Bitrix\HumanResources\Type\NodeEntityType;

class DepartmentSearchTool extends NodeSearchTool
{
	protected NodeEntityType $type = NodeEntityType::DEPARTMENT;
	protected string $permissionId = PermissionDictionary::HUMAN_RESOURCES_STRUCTURE_VIEW;

	public function getName(): string
	{
		return 'department_search';
	}

	public function getDescription(): string
	{
		return 'Search for departments by name. Returns a list of matching departments with their IDs, names, parent IDs, and employee counts.';
	}
}

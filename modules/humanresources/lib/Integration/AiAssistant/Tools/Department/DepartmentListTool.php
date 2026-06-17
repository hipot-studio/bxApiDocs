<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Department;

use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeListTool;
use Bitrix\HumanResources\Type\NodeEntityType;

class DepartmentListTool extends NodeListTool
{
	protected NodeEntityType $type = NodeEntityType::DEPARTMENT;
	protected string $permissionId = PermissionDictionary::HUMAN_RESOURCES_STRUCTURE_VIEW;

	public function getName(): string
	{
		return 'department_list';
	}

	public function getDescription(): string
	{
		return 'Get a full list of all departments in the company structure. Supports pagination with limit and offset.';
	}
}

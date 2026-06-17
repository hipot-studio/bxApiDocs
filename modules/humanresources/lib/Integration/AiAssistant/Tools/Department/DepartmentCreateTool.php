<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Department;

use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeCreateTool;
use Bitrix\HumanResources\Type\NodeEntityType;

class DepartmentCreateTool extends NodeCreateTool
{
	protected NodeEntityType $type = NodeEntityType::DEPARTMENT;
	protected string $permissionId = PermissionDictionary::HUMAN_RESOURCES_DEPARTMENT_CREATE;

	public function getName(): string
	{
		return 'department_create';
	}

	public function getDescription(): string
	{
		return 'Create a new department under `parentId`. '
			. 'Can optionally seed the department with members grouped by role and link or create chats/channels/collabs in one call. '
			. 'Use when the user wants to add a new department to the org structure.';
	}
}

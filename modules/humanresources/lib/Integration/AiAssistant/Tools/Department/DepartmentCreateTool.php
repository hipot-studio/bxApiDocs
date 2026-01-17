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
		return 'create';
	}

	public function getDescription(): string
	{
		return 'Create a new node with the specified parameters when node is a department. 
This tool provides functionality to set up node with employees and communication tools.';
	}
}

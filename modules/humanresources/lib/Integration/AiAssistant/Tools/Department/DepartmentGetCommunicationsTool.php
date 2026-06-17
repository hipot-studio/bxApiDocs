<?php

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Department;

use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeGetCommunicationsTool;
use Bitrix\HumanResources\Type\NodeEntityType;

class DepartmentGetCommunicationsTool extends NodeGetCommunicationsTool
{
	protected NodeEntityType $type = NodeEntityType::DEPARTMENT;
	protected string $permissionId = PermissionDictionary::HUMAN_RESOURCES_STRUCTURE_VIEW;

	public function getName(): string
	{
		return 'department_show_communications';
	}

	public function getDescription(): string
	{
		return 'Get chats, channels, and collabs linked to the given department.';
	}
}

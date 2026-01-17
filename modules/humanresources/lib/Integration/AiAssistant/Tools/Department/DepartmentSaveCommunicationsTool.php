<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Department;

use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeSaveCommunicationsTool;
use Bitrix\HumanResources\Type\NodeEntityType;

class DepartmentSaveCommunicationsTool extends NodeSaveCommunicationsTool
{
	protected NodeEntityType $type = NodeEntityType::DEPARTMENT;
	protected string $permissionId = PermissionDictionary::HUMAN_RESOURCES_DEPARTMENT_CHAT_EDIT;

	public function getName(): string
	{
		return 'save_communications_list';
	}

	public function getDescription(): string
	{
		return 'Add node to chats, channels, or collabs, create default communications, or remove node from communications when node is a department.';
	}
}

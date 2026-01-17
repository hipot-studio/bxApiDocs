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
		return 'show_communications';
	}

	public function getDescription(): string
	{
		return 'Get a list of chats, channels and collabs where the node identified by `nodeId` is added when node is a department.';
	}
}

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
		return 'department_save_communications_list';
	}

	public function getDescription(): string
	{
		return 'Manage chat/channel/collab bindings of a department in one call: '
			. 'create a default one, attach existing, or detach. The three modes can be combined. '
			. 'At least one of `createDefault`, `ids`, or `removeIds` must be provided.';
	}
}

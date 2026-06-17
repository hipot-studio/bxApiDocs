<?php

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Team;

use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeSaveCommunicationsTool;
use Bitrix\HumanResources\Type\NodeEntityType;

class TeamSaveCommunicationsTool extends NodeSaveCommunicationsTool
{
	protected NodeEntityType $type = NodeEntityType::TEAM;
	protected string $permissionId = PermissionDictionary::HUMAN_RESOURCES_TEAM_CHAT_EDIT;

	public function getName(): string
	{
		return 'team_save_communications_list';
	}

	public function getDescription(): string
	{
		return 'Manage chat/channel/collab bindings of a team in one call: '
			. 'create a default one, attach existing, or detach. The three modes can be combined. '
			. 'At least one of `createDefault`, `ids`, or `removeIds` must be provided.';
	}
}

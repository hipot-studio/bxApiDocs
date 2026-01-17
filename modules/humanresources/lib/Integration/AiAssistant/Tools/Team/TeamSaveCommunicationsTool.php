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
		return 'save_communications_list';
	}

	public function getDescription(): string
	{
		return 'Add node to chats, channels, or collabs, create default communications, or remove node from communications when node is a team.';
	}
}

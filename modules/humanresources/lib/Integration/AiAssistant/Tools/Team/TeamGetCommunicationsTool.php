<?php

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Team;

use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeGetCommunicationsTool;
use Bitrix\HumanResources\Type\NodeEntityType;

class TeamGetCommunicationsTool extends NodeGetCommunicationsTool
{
	protected NodeEntityType $type = NodeEntityType::TEAM;
	protected string $permissionId = PermissionDictionary::HUMAN_RESOURCES_TEAM_VIEW;

	public function getName(): string
	{
		return 'team_show_communications';
	}

	public function getDescription(): string
	{
		return 'Get chats, channels, and collabs linked to the given team.';
	}
}

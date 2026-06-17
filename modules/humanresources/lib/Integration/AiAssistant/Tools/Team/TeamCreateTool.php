<?php

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Team;

use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeCreateTool;
use Bitrix\HumanResources\Type\NodeEntityType;

class TeamCreateTool extends NodeCreateTool
{
	protected NodeEntityType $type = NodeEntityType::TEAM;
	protected string $permissionId = PermissionDictionary::HUMAN_RESOURCES_TEAM_CREATE;

	public function getName(): string
	{
		return 'team_create';
	}

	public function getDescription(): string
	{
		return 'Create a new team under `parentId`. '
			. 'Can optionally seed the team with members grouped by role and link or create chats/channels/collabs in one call. '
			. 'Use when the user wants to add a new team to the org structure.';
	}
}

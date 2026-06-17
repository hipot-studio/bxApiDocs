<?php

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Team;

use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeGetChildrenTool;
use Bitrix\HumanResources\Type\NodeEntityType;

class TeamGetChildrenTool extends NodeGetChildrenTool
{
	protected NodeEntityType $type = NodeEntityType::TEAM;
	protected string $permissionId = PermissionDictionary::HUMAN_RESOURCES_TEAM_VIEW;

	public function getName(): string
	{
		return 'team_get_children';
	}

	public function getDescription(): string
	{
		return 'Get direct child teams of the given team.';
	}
}

<?php

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Team;

use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeShowTool;
use Bitrix\HumanResources\Type\NodeEntityType;

class TeamShowTool extends NodeShowTool
{
	protected NodeEntityType $type = NodeEntityType::TEAM;
	protected string $permissionId = PermissionDictionary::HUMAN_RESOURCES_TEAM_VIEW;

	public function getName(): string
	{
		return 'team_show';
	}

	public function getDescription(): string
	{
		return 'Get name, description, and member list (with roles) of the given team.';
	}
}

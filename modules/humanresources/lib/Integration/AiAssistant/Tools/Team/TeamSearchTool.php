<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Team;

use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeSearchTool;
use Bitrix\HumanResources\Type\NodeEntityType;

class TeamSearchTool extends NodeSearchTool
{
	protected NodeEntityType $type = NodeEntityType::TEAM;
	protected string $permissionId = PermissionDictionary::HUMAN_RESOURCES_TEAM_VIEW;

	public function getName(): string
	{
		return 'team_search';
	}

	public function getDescription(): string
	{
		return 'Search for teams by name. Returns a list of matching teams with their IDs, names, parent IDs, and employee counts.';
	}
}

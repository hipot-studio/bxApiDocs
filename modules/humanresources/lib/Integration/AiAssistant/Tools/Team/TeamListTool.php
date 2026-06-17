<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Team;

use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeListTool;
use Bitrix\HumanResources\Type\NodeEntityType;

class TeamListTool extends NodeListTool
{
	protected NodeEntityType $type = NodeEntityType::TEAM;
	protected string $permissionId = PermissionDictionary::HUMAN_RESOURCES_TEAM_VIEW;

	public function getName(): string
	{
		return 'team_list';
	}

	public function getDescription(): string
	{
		return 'Get a full list of all teams in the company structure. Supports pagination with limit and offset.';
	}
}

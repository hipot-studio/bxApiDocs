<?php

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Team;

use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeEditEmployeesTool;
use Bitrix\HumanResources\Type\NodeEntityType;

class TeamEditEmployeesTool extends NodeEditEmployeesTool
{
	protected NodeEntityType $type = NodeEntityType::TEAM;
	protected string $permissionId = PermissionDictionary::HUMAN_RESOURCES_TEAM_MEMBER_ADD;

	public function getName(): string
	{
		return 'team_edit_employees';
	}

	public function getDescription(): string
	{
		return 'DESTRUCTIVE REPLACE. Replaces the full member list of the team. '
			. 'Members absent from the input will be removed. '
			. 'Use only when the user explicitly wants to rewrite the full composition. '
			. 'For adding or re-roling individual members use `team_add_members`.';
	}
}

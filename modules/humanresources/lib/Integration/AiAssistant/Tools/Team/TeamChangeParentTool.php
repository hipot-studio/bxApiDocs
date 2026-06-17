<?php

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Team;

use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeChangeParentTool;
use Bitrix\HumanResources\Type\NodeEntityType;

class TeamChangeParentTool extends NodeChangeParentTool
{
	protected NodeEntityType $type = NodeEntityType::TEAM;
	protected string $permissionId = PermissionDictionary::HUMAN_RESOURCES_TEAM_EDIT;

	public function getName(): string
	{
		return 'team_change_parent';
	}

	public function getDescription(): string
	{
		return 'Reparent a team under a new `parentId`. '
			. 'Side effect: changes the access scope for the team. '
			. 'Always confirm with the user before calling.';
	}
}

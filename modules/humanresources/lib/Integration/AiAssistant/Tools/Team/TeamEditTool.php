<?php

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Team;

use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeEditTool;
use Bitrix\HumanResources\Type\NodeEntityType;

class TeamEditTool extends NodeEditTool
{
	protected NodeEntityType $type = NodeEntityType::TEAM;
	protected string $permissionId = PermissionDictionary::HUMAN_RESOURCES_TEAM_EDIT;

	public function getName(): string
	{
		return 'team_edit';
	}

	public function getDescription(): string
	{
		return 'Update the name, description, and/or color of an existing team. '
			. 'Does not move the node, change members, or alter communications. '
			. 'Use `team_change_parent` to reparent and `team_edit_employees` to replace the member list.';
	}
}

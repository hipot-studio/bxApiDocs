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
		return 'change_parent';
	}

	public function getDescription(): string
	{
		return 'Change node parent to `parentId` for the node identified by `nodeId` when node is a team. Use this function to change level of subordination';
	}
}

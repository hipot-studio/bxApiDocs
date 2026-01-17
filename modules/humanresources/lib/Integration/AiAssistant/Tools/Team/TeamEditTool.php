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
		return 'edit';
	}

	public function getDescription(): string
	{
		return 'Rename name to `name` and description to `description` for the node identified by `nodeId` when node is a team. 
Use this function to update node information';
	}
}

<?php

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Team;

use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeMoveEmployeesTool;
use Bitrix\HumanResources\Type\NodeEntityType;

class TeamMoveEmployeesTool extends NodeMoveEmployeesTool
{
	protected NodeEntityType $type = NodeEntityType::TEAM;
	protected string $permissionId = PermissionDictionary::HUMAN_RESOURCES_TEAM_MEMBER_REMOVE;

	public function getName(): string
	{
		return 'move_employees';
	}

	public function getDescription(): string
	{
		return 'Move employees to another node identified by `nodeId` when node is a team. 
Use this function to move node members. Accepts a list of user IDs grouped by roles.';
	}
}

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
		return 'edit_employees';
	}

	public function getDescription(): string
	{
		return 'Edit employee list for the node identified by `nodeId` when node is a team. 
Use this function to update node members. Accepts a list of user IDs grouped by roles.';
	}
}

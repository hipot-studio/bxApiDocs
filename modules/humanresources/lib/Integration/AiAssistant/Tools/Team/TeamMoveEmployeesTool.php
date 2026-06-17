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
		return 'team_move_employees';
	}

	public function getDescription(): string
	{
		return 'Move users to the target team. '
			. 'For department membership use `department_move_employees`.';
	}
}

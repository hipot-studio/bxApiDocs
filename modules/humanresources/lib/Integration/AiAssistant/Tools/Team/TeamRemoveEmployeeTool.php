<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Team;

use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeRemoveEmployeeTool;
use Bitrix\HumanResources\Type\NodeEntityType;

class TeamRemoveEmployeeTool extends NodeRemoveEmployeeTool
{
	protected NodeEntityType $type = NodeEntityType::TEAM;
	protected string $permissionId = PermissionDictionary::HUMAN_RESOURCES_TEAM_MEMBER_REMOVE;

	public function getName(): string
	{
		return 'team_remove_employee';
	}

	public function getDescription(): string
	{
		return 'Remove members from a team. Use when the user explicitly asks to remove someone from a team.';
	}
}

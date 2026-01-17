<?php

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Team;

use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeCreateTool;
use Bitrix\HumanResources\Type\NodeEntityType;

class TeamCreateTool extends NodeCreateTool
{
	protected NodeEntityType $type = NodeEntityType::TEAM;
	protected string $permissionId = PermissionDictionary::HUMAN_RESOURCES_TEAM_CREATE;

	public function getName(): string
	{
		return 'create';
	}

	public function getDescription(): string
	{
		return 'Create a new node with the specified parameters when node is a team. 
This tool provides functionality to set up node with employees and communication tools.';
	}
}

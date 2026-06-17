<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Team;

use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeAddMembersTool;
use Bitrix\HumanResources\Type\NodeEntityType;

class TeamAddMembersTool extends NodeAddMembersTool
{
	protected NodeEntityType $type = NodeEntityType::TEAM;
	protected string $permissionId = PermissionDictionary::HUMAN_RESOURCES_TEAM_MEMBER_ADD;

	public function getName(): string
	{
		return 'team_add_members';
	}

	public function getDescription(): string
	{
		return 'Add members to the team identified by `nodeId`, or change the role of existing members. '
			. 'Does NOT remove members that are absent from the input. '
			. 'Accepts a single `roleXmlId` applied to every user in `userIds` — '
			. 'call this tool multiple times to assign different roles. '
			. 'Use this for typical requests like "make X the head" or "add Y as a member".'
		;
	}
}

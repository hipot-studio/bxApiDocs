<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Department;

use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Node\NodeAddMembersTool;
use Bitrix\HumanResources\Type\NodeEntityType;

class DepartmentAddMembersTool extends NodeAddMembersTool
{
	protected NodeEntityType $type = NodeEntityType::DEPARTMENT;
	protected string $permissionId = PermissionDictionary::HUMAN_RESOURCES_EMPLOYEE_ADD_TO_DEPARTMENT;

	public function getName(): string
	{
		return 'department_add_members';
	}

	public function getDescription(): string
	{
		return 'Add employees to the department identified by `nodeId`, or change the role of existing members. '
			. 'Does NOT remove members that are absent from the input. '
			. 'Accepts a single `roleXmlId` applied to every user in `userIds` — '
			. 'call this tool multiple times to assign different roles. '
			. 'Use this for typical requests like "make X the head" or "add Y as an employee".'
		;
	}
}

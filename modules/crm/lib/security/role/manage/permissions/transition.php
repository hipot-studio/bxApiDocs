<?php

namespace Bitrix\Crm\Security\Role\Manage\Permissions;

use Bitrix\Main\Localization\Loc;

class Transition extends Permission
{
	const TRANSITION_ANY = 'ANY';
	const TRANSITION_INHERIT = 'INHERIT';
	public function code(): string
	{
		return 'TRANSITION';
	}

	public function name(): string
	{
		return Loc::getMessage('CRM_SECURITY_ROLE_PERMS_HEAD_TRANSITION');
	}

	public function canAssignPermissionToStages(): bool
	{
		return true;
	}

	public function sortOrder(): ?int
	{
		return 8;
	}

	public function getDefaultSettings(): array
	{
		return [self::TRANSITION_ANY];
	}
}
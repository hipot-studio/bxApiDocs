<?php

namespace Bitrix\Crm\Security\Role\Manage\Permissions;

use Bitrix\Main\Localization\Loc;

class HideSum extends Permission
{
	public function code(): string
	{
		return 'HIDE_SUM';
	}

	public function name(): string
	{
		return Loc::getMessage('CRM_SECURITY_ROLE_PERMS_HEAD_HIDE_SUM');
	}

	public function canAssignPermissionToStages(): bool
	{
		return true;
	}
}

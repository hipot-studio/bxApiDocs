<?php

namespace Bitrix\Crm\Security\Role\Manage\Permissions;

class MyCardView extends Permission
{
    public function code(): string
    {
        return 'MYCARDVIEW';
    }

    public function name(): string
    {
        return GetMessage('CRM_SECURITY_ROLE_PERMS_HEAD_MYCARDVIEW');
    }

    public function canAssignPermissionToStages(): bool
	{
		return false;
	}

	public function getDefaultAttribute(): ?string
	{
		return BX_CRM_PERM_ALL;
	}
}
<?php

namespace Bitrix\Crm\Security\Role\Manage\Permissions\Configuration;

use Bitrix\Crm\Security\Role\Manage\Permissions\Permission;
use Bitrix\Crm\Security\Role\UIAdapters\AccessRights\ControlMapper\BaseControlMapper;
use Bitrix\Crm\Security\Role\UIAdapters\AccessRights\ControlMapper\Toggler;
use Bitrix\Crm\Service\UserPermissions\ConfigurationOperation;
use Bitrix\Main\Localization\Loc;

final class ManageDynamicTypes extends Permission
{
	public function code(): string
	{
		return (string)ConfigurationOperation::ManageDynamicTypes->getStoredCode();
	}

	public function name(): string
	{
		return (string)Loc::getMessage(ConfigurationOperation::ManageDynamicTypes->getLabelKey());
	}

	public function canAssignPermissionToStages(): bool
	{
		return false;
	}

	protected function createDefaultControlMapper(): BaseControlMapper
	{
		return new Toggler();
	}
}

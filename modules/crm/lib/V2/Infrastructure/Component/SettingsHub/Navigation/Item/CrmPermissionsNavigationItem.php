<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\Security\Role\Manage\RoleSelectionManager;
use Bitrix\Crm\Service\Router;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubUrlNavigationItem;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Uri;

Loc::loadMessages(__FILE__);

final class CrmPermissionsNavigationItem implements SettingsHubUrlNavigationItem
{
	public function __construct(
		private Router $router,
		private RoleSelectionManager $roleSelectionManager,
	)
	{
	}

	public function id(): string
	{
		return 'crm-permissions';
	}

	public function isVisible(UserPermissions $permissions): bool
	{
		return $permissions->isCrmAdmin();
	}

	public function checkAvailability(UserPermissions $permissions): Result
	{
		if (!$this->roleSelectionManager->hasPermissionsToEditRights())
		{
			return (new Result())->addError(
				new Error(
					Loc::getMessage('CRM_SETTINGS_HUB_CRMPERMISSIONS_NO_ACCESS'),
					'CRMPERMISSIONS_NO_ACCESS',
				),
			);
		}

		if (!$this->roleSelectionManager->isAvailableTool())
		{
			return (new Result())->addError(
				new Error(
					Loc::getMessage('CRM_SETTINGS_HUB_CRMPERMISSIONS_TOOL_UNAVAILABLE'),
					'CRMPERMISSIONS_TOOL_UNAVAILABLE',
				),
			);
		}

		return new Result();
	}

	public function url(): ?Uri
	{
		return $this->router->getPermissionsUrl();
	}
}

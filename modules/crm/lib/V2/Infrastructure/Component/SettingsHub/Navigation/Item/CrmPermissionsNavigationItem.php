<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\Security\Role\Manage\RoleSelectionManager;
use Bitrix\Crm\Service\Router;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubUrlNavigationItem;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\Web\Uri;

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
		return true;
	}

	public function canRead(UserPermissions $permissions): bool
	{
		return $this->roleSelectionManager->hasPermissionsToEditRights()
			&& $this->roleSelectionManager->isAvailableTool();
	}

	public function url(): ?Uri
	{
		return $this->router->getPermissionsUrl();
	}
}

<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\Service\Router;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubUrlNavigationItem;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\Web\Uri;

final class AutomationLeadNavigationItem implements SettingsHubUrlNavigationItem
{
	public function __construct(
		private Router $router,
		private bool $isRobotsAvailable,
		private bool $isAutomationAvailable,
	)
	{
	}

	public function id(): string
	{
		return 'automation-lead';
	}

	public function isVisible(UserPermissions $permissions): bool
	{
		return $permissions->isCrmAdmin();
	}

	public function canRead(UserPermissions $permissions): bool
	{
		return $this->isVisible($permissions)
			&& $this->isRobotsAvailable
			&& $this->isAutomationAvailable;
	}

	public function url(): ?Uri
	{
		return $this->router->getAutomationUrl(\CCrmOwnerType::Lead, 0);
	}
}

<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubUrlNavigationItem;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\Web\Uri;

final class LocationsNavigationItem implements SettingsHubUrlNavigationItem
{
	public function __construct(
		private bool $isSaleAvailable,
		private bool $isCurrencyAvailable,
		private bool $isCatalogAvailable,
	)
	{
	}

	public function id(): string
	{
		return 'locations';
	}

	public function isVisible(UserPermissions $permissions): bool
	{
		return true;
	}

	public function canRead(UserPermissions $permissions): bool
	{
		return $permissions->isCrmAdmin()
			&& $this->isSaleAvailable
			&& $this->isCurrencyAvailable
			&& $this->isCatalogAvailable;
	}

	public function url(): ?Uri
	{
		return new Uri(SITE_DIR . 'crm/configs/locations/');
	}
}

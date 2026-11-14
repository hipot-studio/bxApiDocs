<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubUrlNavigationItem;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Uri;

Loc::loadMessages(__FILE__);

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
		return $permissions->isCrmAdmin();
	}

	public function checkAvailability(UserPermissions $permissions): Result
	{
		if (!$this->isSaleAvailable)
		{
			return (new Result())->addError(
				new Error(
					Loc::getMessage('CRM_SETTINGS_HUB_LOCATIONS_SALE_UNAVAILABLE'),
					'LOCATIONS_SALE_UNAVAILABLE',
				),
			);
		}

		if (!$this->isCurrencyAvailable)
		{
			return (new Result())->addError(
				new Error(
					Loc::getMessage('CRM_SETTINGS_HUB_LOCATIONS_CURRENCY_UNAVAILABLE'),
					'LOCATIONS_CURRENCY_UNAVAILABLE',
				),
			);
		}

		if (!$this->isCatalogAvailable)
		{
			return (new Result())->addError(
				new Error(
					Loc::getMessage('CRM_SETTINGS_HUB_LOCATIONS_CATALOG_UNAVAILABLE'),
					'LOCATIONS_CATALOG_UNAVAILABLE',
				),
			);
		}

		return new Result();
	}

	public function url(): ?Uri
	{
		return new Uri(SITE_DIR . 'crm/configs/locations/');
	}
}

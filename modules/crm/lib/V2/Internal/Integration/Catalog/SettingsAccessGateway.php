<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Catalog;

use Bitrix\Catalog\Access\AccessController;
use Bitrix\Catalog\Access\ActionDictionary;
use Bitrix\Main\Loader;

class SettingsAccessGateway
{
	public function isCatalogAvailable(): bool
	{
		return Loader::includeModule('catalog');
	}

	public function isCurrencyAvailable(): bool
	{
		return Loader::includeModule('currency');
	}

	public function isSaleAvailable(): bool
	{
		return Loader::includeModule('sale');
	}

	public function isIblockAvailable(): bool
	{
		return Loader::includeModule('iblock');
	}

	public function canEditVat(): bool
	{
		return AccessController::getCurrent()->check(ActionDictionary::ACTION_VAT_EDIT);
	}

	public function canReadCatalog(): bool
	{
		return AccessController::getCurrent()->check(ActionDictionary::ACTION_CATALOG_READ);
	}
}

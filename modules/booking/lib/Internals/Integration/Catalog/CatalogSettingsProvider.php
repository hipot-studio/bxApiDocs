<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Catalog;

use Bitrix\Catalog\Grid\Menu\ProductGridCreateButton;
use Bitrix\Crm\Product\Catalog;
use Bitrix\Crm\Product\Url\ProductBuilder;
use Bitrix\Main\Loader;

class CatalogSettingsProvider
{
	public function getCatalogPresetUrl(): string|null
	{
		if (
			!Loader::includeModule('crm')
			|| !Loader::includeModule('catalog')
		)
		{
			return null;
		}

		$urlBuilder = new ProductBuilder();
		$urlBuilder->setIblockId((int)Catalog::getDefaultId());
		$urlBuilder->setUrlParams([
			'sliderList' => 'Y',
			'showToolbar' => 'Y',
			'createBtnItems' => [ProductGridCreateButton::BTN_SERVICE],
			'preset_id' => 'booking_services',
			'apply_filter' => 'Y',
			'with_preset' => 'Y',
		]);

		return $urlBuilder->getSectionListUrl(0);
	}
}

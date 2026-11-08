<?php

namespace Bitrix\Crm\Integration\Catalog\EntitySelector;

use Bitrix\Catalog\v2\Iblock\IblockInfo;
use Bitrix\Catalog\v2\Integration\UI\EntitySelector\ProductProvider;
use Bitrix\Catalog\v2\IoC\ServiceContainer;
use Bitrix\Crm\Integration\UI\EntitySelector\CatalogProductProvider;
use Bitrix\Crm\Product\Catalog;
use Bitrix\Crm\Product\Price;

final class CrmCatalogProductProvider extends ProductProvider
{
	protected const ENTITY_ID = CatalogProductProvider::ENTITY_ID;

	public function __construct(array $options = [])
	{
		parent::__construct(
			[
				'iblockId' => (int)Catalog::getDefaultId(),
				'basePriceId' => $options['basePriceId'] ?? Price::getBaseId(),
			]
			+ $options
		);
	}

	// The parent caches the info in a static variable that ignores the iblock and is shared with every descendant
	protected function getIblockInfo(): ?IblockInfo
	{
		return ServiceContainer::getIblockInfo($this->getIblockId());
	}
}

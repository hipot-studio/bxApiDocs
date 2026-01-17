<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Catalog;

use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Exception\CatalogService\CreateCatalogServiceException;
use Bitrix\Catalog\Access\ActionDictionary;
use Bitrix\Catalog\MeasureTable;
use Bitrix\Catalog\ProductTable;
use Bitrix\Catalog\v2\IoC\ServiceContainer;
use Bitrix\Catalog\v2\Product\BaseProduct;
use Bitrix\Crm\Product\Url\ProductBuilder;
use Bitrix\Iblock\ElementTable;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Catalog\Access\AccessController;
use Bitrix\Crm\Product\Catalog;
use Bitrix\Catalog\GroupTable;

class ServiceSkuCreator
{
	public function create(int $iblockId, string $serviceName): int
	{
		if (!Loader::includeModule('catalog'))
		{
			throw new CreateCatalogServiceException('Catalog module is not installed');
		}

		if (
			!\CIBlockSectionRights::UserHasRightTo($iblockId, 0, 'section_element_bind')
			|| !AccessController::getCurrent()->check(ActionDictionary::ACTION_PRODUCT_ADD)
		)
		{
			throw new CreateCatalogServiceException('Access denied');
		}

		$productFactory = ServiceContainer::getProductFactory($iblockId);
		if (!$productFactory)
		{
			throw new CreateCatalogServiceException('Iblock not found');
		}

		/** @var BaseProduct $product */
		$product = $productFactory
			->createEntity()
			->setType(ProductTable::TYPE_SERVICE)
			->setFields([
				'IBLOCK_ID' => $iblockId,
				'NAME' => $serviceName,
				'CODE' => $this->getCode($iblockId, $serviceName),
				'MEASURE' => $this->getMeasureId(),
				'VAT_INCLUDED' => Option::get('catalog', 'default_product_vat_included') === 'Y'
					? ProductTable::STATUS_YES
					: ProductTable::STATUS_NO
				,
				'AVAILABLE' => ProductTable::STATUS_YES,
			])
		;

		$result = $product->save();
		if (!$result->isSuccess())
		{
			throw new CreateCatalogServiceException(implode(', ', $result->getErrorMessages()));
		}

		$id = $product->getId();
		if ($id === null)
		{
			throw new CreateCatalogServiceException('Service creation error');
		}

		return $id;
	}

	public function getEntitySelectorEntityOptions(int $userId): array
	{
		if (
			!Loader::includeModule('catalog')
			|| !Loader::includeModule('crm')
			|| !Container::getCatalogServiceSkuProvider()->checkCatalogReadAccess($userId)
		)
		{
			return [];
		}

		return [
			'iblockId' => Catalog::getDefaultId(),
			'basePriceId' => GroupTable::getBasePriceTypeId(),
			'showPriceInCaption' => true,
			'restrictedProductTypes' => array_filter(
				ProductTable::getProductTypes(),
				static fn($item) => $item !== ProductTable::TYPE_SERVICE
			),
			'linkType' => ProductBuilder::TYPE_ID,
			'defaultItemAvatar' => '/bitrix/js/booking/component/actions-popup/images/sku-icon.svg',
			'canCreate' => Container::getCatalogServiceSkuProvider()->checkCatalogProductCreateAccess($userId),
		];
	}

	private function getCode(int $iblockId, string $serviceName): string|null
	{
		if ($serviceName === '')
		{
			return null;
		}

		$result = (new \CIBlockElement())->generateMnemonicCode($serviceName, $iblockId);
		if ($result === null)
		{
			return null;
		}

		if (\CIBlock::isUniqueElementCode($iblockId))
		{
			$elementRaw = ElementTable::getList([
				'filter' => ['=CODE' => $result],
				'select' => ['ID'],
				'limit' => 1,
			]);

			if ($elementRaw->fetch())
			{
				$result = uniqid($result . '_');
			}
		}

		return $result;
	}

	private function getMeasureId(): int|null
	{
		$measure = MeasureTable::getRow([
			'select' => ['ID'],
			'filter' => ['=IS_DEFAULT' => 'Y'],
		]);

		return $measure ? (int)$measure['ID'] : null;
	}
}

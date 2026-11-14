<?php

namespace Bitrix\Crm\Integration\Catalog;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Crm\Integration\Catalog\Contractor\StoreDocumentProvider;
use Bitrix\Crm\Integration\Catalog\Contractor\AgentContractProvider;
use Bitrix\Crm\Integration\Catalog\Contractor\Converter;
use Bitrix\Crm\Service\Sale\Reservation\StoreActiveReserveChecker;
use Bitrix\Catalog\v2\Contractor\Provider;

/**
 * Class EventHandler
 *
 * @package Bitrix\Crm\Integration\Catalog
 */
class EventHandler
{
	/**
	 * @return \Bitrix\Crm\Integration\Catalog\Contractor\Provider[]
	 */
	public static function onGetContractorsProviderEventHandler(): array
	{
		if (!Loader::includeModule('catalog'))
		{
			return [];
		}

		return [
			Provider\Manager::PROVIDER_STORE_DOCUMENT => new StoreDocumentProvider(),
			Provider\Manager::PROVIDER_AGENT_CONTRACT => new AgentContractProvider(),
		];
	}

	public static function onGetContractorsConverterEventHandler(): Converter
	{
		return new Converter();
	}

	/**
	 * Vetoes the deletion of a store that still holds an active reserve of a product row:
	 * an unshipped part of the reserve of a row that is not of a closed deal, whatever its end date.
	 *
	 * @see StoreActiveReserveChecker
	 *
	 * @param int|string $storeId
	 * @return bool
	 */
	public static function onBeforeCatalogStoreDelete($storeId): bool
	{
		$storeId = (int)$storeId;
		if ($storeId <= 0)
		{
			return true;
		}

		if (!(new StoreActiveReserveChecker())->hasActiveReserve($storeId))
		{
			return true;
		}

		global $APPLICATION;
		$APPLICATION->ThrowException(
			Loc::getMessage('CRM_INTEGRATION_CATALOG_STORE_HAS_DEAL_RESERVES'),
			'CRM_STORE_HAS_DEAL_RESERVES'
		);

		return false;
	}
}

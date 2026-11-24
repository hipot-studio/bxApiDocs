<?php

namespace Bitrix\Sale\Reservation\Event\Handler;

use Bitrix\Main\Localization\Loc;
use Bitrix\Sale\Reservation\Internals\BasketReservationTable;

class CatalogStoreDeleteHandler
{
	/**
	 * Handler of the catalog event OnBeforeCatalogStoreDelete: a store with an active order reserve is not deleted.
	 *
	 * @param int|string $storeId
	 *
	 * @return bool
	 */
	public static function onBeforeCatalogStoreDelete($storeId): bool
	{
		$storeId = (int)$storeId;
		if ($storeId <= 0)
		{
			return true;
		}

		$reserve = BasketReservationTable::getRow([
			'select' => ['ID'],
			'filter' => [
				'=STORE_ID' => $storeId,
				'>QUANTITY' => 0,
			],
			'limit' => 1,
		]);
		if (!$reserve)
		{
			return true;
		}

		global $APPLICATION;
		$APPLICATION->ThrowException(
			Loc::getMessage('SALE_CATALOG_STORE_DELETE_HANDLER_HAS_ORDER_RESERVES'),
			'SALE_STORE_HAS_ORDER_RESERVES'
		);

		return false;
	}
}

<?php

namespace Bitrix\Crm\Integration\DocumentGenerator\DataProvider;

use Bitrix\Catalog\StoreDocumentTable;
use Bitrix\Main\Localization\Loc;

/**
 * Class StoreDocumentStoreAdjustment
 *
 * @package Bitrix\Crm\Integration\DocumentGenerator\DataProvider
 */
class StoreDocumentStoreAdjustment extends StoreDocument
{
	protected function getDocumentType(): string
	{
		return StoreDocumentTable::TYPE_STORE_ADJUSTMENT;
	}

	/**
	 * @inheritDoc
	 */
	public static function getLangName()
	{
		return Loc::getMessage('CRM_DOCGEN_DATAPROVIDER_SD_ADJUSTMENT_TITLE');
	}
}

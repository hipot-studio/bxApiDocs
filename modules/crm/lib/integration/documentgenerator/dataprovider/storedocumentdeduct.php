<?php

namespace Bitrix\Crm\Integration\DocumentGenerator\DataProvider;

use Bitrix\Catalog\StoreDocumentTable;
use Bitrix\Main\Localization\Loc;

class StoreDocumentDeduct extends StoreDocument
{
	protected function getDocumentType(): string
	{
		return StoreDocumentTable::TYPE_DEDUCT;
	}

	/**
	 * @inheritDoc
	 */
	public static function getLangName()
	{
		return Loc::getMessage('CRM_DOCGEN_DATAPROVIDER_SD_DEDUCT_TITLE');
	}
}

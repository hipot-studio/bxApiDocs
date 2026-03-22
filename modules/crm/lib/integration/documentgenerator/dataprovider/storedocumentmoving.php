<?php

namespace Bitrix\Crm\Integration\DocumentGenerator\DataProvider;

use Bitrix\Catalog\StoreDocumentTable;
use Bitrix\Main\Localization\Loc;

/**
 * Class StoreDocumentMoving
 *
 * @package Bitrix\Crm\Integration\DocumentGenerator\DataProvider
 */
class StoreDocumentMoving extends StoreDocument
{
	protected function getDocumentType(): string
	{
		return StoreDocumentTable::TYPE_MOVING;
	}

	/**
	 * @inheritDoc
	 */
	public static function getLangName()
	{
		return Loc::getMessage('CRM_DOCGEN_DATAPROVIDER_SD_MOVING_TITLE');
	}
}

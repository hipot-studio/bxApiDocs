<?php

namespace Bitrix\Crm\Import\Deprecated;

use Bitrix\Crm\Import\ImportOperation;

/**
 * @deprecated
 * @see ImportOperation
 */
class YandexCsvFileImport extends OutlookCsvFileImport
{
	public function getDefaultEncoding()
	{
		return $this->headerLanguage === 'ru' ? 'Windows-1251' : 'UTF-8';
	}
	public function getDefaultSeparator()
	{
		return ',';
	}
}

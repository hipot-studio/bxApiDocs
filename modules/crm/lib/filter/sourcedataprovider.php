<?php

namespace Bitrix\Crm\Filter;

use Bitrix\Crm\StatusTable;
use Bitrix\Main\Localization\Loc;

class SourceDataProvider
{
	public static function getListWithEmptyValue(): array
	{
		$sources = StatusTable::getStatusesList(StatusTable::ENTITY_ID_SOURCE);
		$result = [
			'' => Loc::getMessage('CRM_FILTER_SOURCE_NOT_SELECTED'),
		];

		foreach ($sources as $sourceId => $sourceName)
		{
			$result[$sourceId] = $sourceName;
		}

		return $result;
	}
}

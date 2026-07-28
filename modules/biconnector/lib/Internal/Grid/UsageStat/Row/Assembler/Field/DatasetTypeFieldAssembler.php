<?php

namespace Bitrix\BIConnector\Internal\Grid\UsageStat\Row\Assembler\Field;

use Bitrix\BIConnector\ExternalSource\Internal\ExternalDatasetTable;
use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Main\Localization\Loc;

final class DatasetTypeFieldAssembler extends FieldAssembler
{
	public function prepareRows(array $rowList): array
	{
		if (empty($this->getColumnIds()) || empty($rowList))
		{
			return $rowList;
		}

		$sourceIds = [];
		foreach ($rowList as $row)
		{
			$sourceId = $row['data']['SOURCE_ID'] ?? null;
			if ($sourceId !== null && $sourceId !== '')
			{
				$sourceIds[(string)$sourceId] = true;
			}
		}

		$customNames = [];
		if (!empty($sourceIds))
		{
			$names = ExternalDatasetTable::getList([
				'select' => ['NAME'],
				'filter' => [
					'=NAME' => array_keys($sourceIds),
				],
			])->fetchAll();

			$customNames = array_flip(array_column($names, 'NAME'));
		}

		$customLabel = (string)Loc::getMessage('BIC_USAGE_STAT_GRID_ROW_DATASET_TYPE_CUSTOM');
		$systemLabel = (string)Loc::getMessage('BIC_USAGE_STAT_GRID_ROW_DATASET_TYPE_SYSTEM');

		foreach ($rowList as &$row)
		{
			$row['columns'] ??= [];
			$sourceId = (string)($row['data']['SOURCE_ID'] ?? '');
			$label = isset($customNames[$sourceId]) ? $customLabel : $systemLabel;

			foreach ($this->getColumnIds() as $columnId)
			{
				$row['columns'][$columnId] = $label;
			}
		}
		unset($row);

		return $rowList;
	}
}

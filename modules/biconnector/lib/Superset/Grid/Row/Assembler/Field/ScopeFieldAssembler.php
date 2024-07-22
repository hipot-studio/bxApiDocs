<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field;

use Bitrix\BIConnector\Superset\Grid\Settings\DashboardSettings;
use Bitrix\BIConnector\Superset\Scope\ScopeService;
use Bitrix\Main\Grid\Row\FieldAssembler;

/**
 * @method DashboardSettings getSettings()
 */
class ScopeFieldAssembler extends FieldAssembler
{
	protected function prepareColumn($value)
	{
		if (!$value['SCOPE'])
		{
			return '';
		}

		$text = implode(', ', ScopeService::getInstance()->getScopeNameList($value['SCOPE']));

		return $text;
	}

	protected function prepareRow(array $row): array
	{
		if (empty($this->getColumnIds()))
		{
			return $row;
		}

		$row['columns'] ??= [];
		foreach ($this->getColumnIds() as $columnId)
		{
			$row['columns'][$columnId] = $this->prepareColumn($row['data']);
		}

		return $row;
	}
}

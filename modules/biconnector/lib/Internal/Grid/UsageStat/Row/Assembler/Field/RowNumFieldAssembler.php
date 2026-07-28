<?php

namespace Bitrix\BIConnector\Internal\Grid\UsageStat\Row\Assembler\Field;

use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Main\Localization\Loc;

final class RowNumFieldAssembler extends FieldAssembler
{
	protected function prepareColumn($value)
	{
		if ($value === null)
		{
			return Loc::getMessage('BIC_USAGE_STAT_GRID_ROW_ROW_NUM_NO_DATA');
		}

		$str = number_format((float)$value, 0, '.', ' ');
		$str = str_replace(' ', '<span></span>', $str);

		return '<span class="biconnector-usage-stat-number">' . $str . '</span>';
	}
}

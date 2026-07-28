<?php

namespace Bitrix\BIConnector\Internal\Grid\UsageStat\Row\Assembler\Field;

use Bitrix\Main\Grid\Row\FieldAssembler;

final class RealTimeFieldAssembler extends FieldAssembler
{
	protected function prepareColumn($value)
	{
		if (!$value)
		{
			return $value;
		}

		$str = number_format((float)$value, 3, '.', ' ');
		$str = str_replace(' ', '<span></span>', $str);

		return '<span class="biconnector-usage-stat-number">' . $str . '</span>';
	}
}

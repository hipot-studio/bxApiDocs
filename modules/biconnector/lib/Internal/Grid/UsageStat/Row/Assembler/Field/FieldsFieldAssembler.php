<?php

namespace Bitrix\BIConnector\Internal\Grid\UsageStat\Row\Assembler\Field;

use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Main\Localization\Loc;

final class FieldsFieldAssembler extends FieldAssembler
{
	private const MAX_VISIBLE = 5;

	protected function prepareColumn($value)
	{
		if (!$value)
		{
			return $value;
		}

		$fields = explode(', ', (string)$value);
		if (count($fields) <= self::MAX_VISIBLE)
		{
			return (string)$value;
		}

		$visible = implode(', ', array_slice($fields, 0, self::MAX_VISIBLE));

		return Loc::getMessage(
			'BIC_USAGE_STAT_GRID_ROW_FIELDS_LIST',
			[
				'#FIELDS#' => $visible,
				'[link]' => '<a class="biconnector-usage-stat-action-link" onclick="return showMore(this, \''
					. \CUtil::JSEscape((string)$value)
					. '\');">',
				'#N#' => count($fields) - self::MAX_VISIBLE,
				'[/link]' => '</a>',
			]
		);
	}
}

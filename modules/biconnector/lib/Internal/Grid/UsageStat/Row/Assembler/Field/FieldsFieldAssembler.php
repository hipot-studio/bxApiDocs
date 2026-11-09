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
			return htmlspecialcharsbx((string)$value);
		}

		$visible = implode(', ', array_slice($fields, 0, self::MAX_VISIBLE));
		$rest = implode(', ', array_slice($fields, self::MAX_VISIBLE));

		return Loc::getMessage(
			'BIC_USAGE_STAT_GRID_ROW_FIELDS_LIST',
			[
				'#FIELDS#' => htmlspecialcharsbx($visible),
				'[link]' => '<button type="button" class="biconnector-usage-stat-action-link"'
					. ' data-bic-focus-role="more-fields"'
					. ' data-bic-rest="' . htmlspecialcharsbx($rest) . '"'
					. ' onclick="return showMore(this);">',
				'#N#' => count($fields) - self::MAX_VISIBLE,
				'[/link]' => '</button>',
			]
		);
	}
}

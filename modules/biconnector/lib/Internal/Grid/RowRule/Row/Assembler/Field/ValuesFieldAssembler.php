<?php

namespace Bitrix\BIConnector\Internal\Grid\RowRule\Row\Assembler\Field;

use Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field\Base\TrimmedTextFieldAssembler;

/**
 * Titles of the constants and tokens of a rule in one line.
 * A rule without readable values gets an empty cell: on null the grid would print the raw array of titles.
 * The parent drops any falsy text, so a single value "0" is printed here.
 */
final class ValuesFieldAssembler extends TrimmedTextFieldAssembler
{
	protected function prepareColumn($value): string
	{
		$text = implode(', ', array_map('strval', (array)$value));

		$cell = parent::prepareColumn($text);
		if ($cell === null && $text !== '')
		{
			$escaped = htmlspecialcharsbx($text);

			return "<span title=\"$escaped\">$escaped</span>";
		}

		return $cell ?? '';
	}
}

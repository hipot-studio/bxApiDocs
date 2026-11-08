<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Filter;

/**
 * Conditions on the presence of a value - the "---" menu of a row of the web filter, as
 * `BX.Filter.AdditionalFilter.Type` names them. The client sends the name back as the suffix of the
 * filter key (`TITLE_isEmpty`), so the wording is fixed by the storage shared with the web.
 *
 * The mobile filter offers what it can word; a condition the web names and this enum does not is
 * dropped from the descriptor rather than sent unworded.
 */
enum AdditionalFilterType: string
{
	case IsEmpty = 'isEmpty';
	case HasAnyValue = 'hasAnyValue';

	/**
	 * @return string[] every condition the mobile filter knows, in the order the row offers them
	 */
	public static function names(): array
	{
		return array_column(self::cases(), 'value');
	}
}

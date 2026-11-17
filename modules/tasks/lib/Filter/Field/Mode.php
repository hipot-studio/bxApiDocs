<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Filter\Field;

/**
 * How the values selected in a filter field are matched.
 *
 * Case values are shared with the tasks.filter-field-mode extension: EQUAL appears in the mode
 * declaration handed to it, but only EXCLUDE and EMPTY ever come back in the satellite value and
 * reach saved filter presets. EQUAL is an empty or absent satellite value, as is anything unknown.
 */
enum Mode: string
{
	case EQUAL = 'equal';
	case EXCLUDE = 'exclude';
	case EMPTY = 'empty';

	/**
	 * Key of the satellite value carrying the picked mode next to the field values.
	 */
	public static function satelliteKey(string $fieldId): string
	{
		return $fieldId . '_subMode';
	}
}

<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Quota;

use Bitrix\Disk\Driver;

/**
 * Columns of the limits table an administrator keeps visible.
 *
 * The setting is personal and belongs to the interface alone: the page reads it while it is being
 * built, so a column somebody hid never blinks on the screen before the interface hides it, and the
 * interface writes it back itself the way the product writes every setting of the kind.
 *
 * A saved value nobody knows any more shows the whole table rather than a part of it: an
 * administrator left with the names alone would read their own setting as a broken page.
 */
final class QuotaTableSettings
{
	private const OPTION_NAME = 'quota_table';
	private const COLUMNS_VALUE_NAME = 'columns';

	/** Columns the administrator may hide, named as the list orders by them. */
	private const COLUMNS = [
		QuotaListProvider::SORT_QUOTA,
		QuotaListProvider::SORT_USED,
		QuotaListProvider::SORT_USAGE,
	];

	/**
	 * @param int $userId Administrator the page is built for.
	 * @return string[] Columns left visible, in the order the table shows them.
	 */
	public static function getVisibleColumns(int $userId): array
	{
		$visible = array_values(array_intersect(self::COLUMNS, self::readSavedColumns($userId)));

		return $visible === [] ? self::COLUMNS : $visible;
	}

	/**
	 * The value is written by the interface, so it is read for what it is: a list of names, or the
	 * comma separated line such lists are kept in across the product.
	 *
	 * @return string[]
	 */
	private static function readSavedColumns(int $userId): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		$saved = \CUserOptions::getOption(Driver::INTERNAL_MODULE_ID, self::OPTION_NAME, [], $userId);
		$columns = is_array($saved) ? ($saved[self::COLUMNS_VALUE_NAME] ?? null) : null;

		if (is_string($columns))
		{
			$columns = explode(',', $columns);
		}

		if (!is_array($columns))
		{
			return [];
		}

		$names = [];
		foreach ($columns as $column)
		{
			if (is_string($column))
			{
				$names[] = trim($column);
			}
		}

		return $names;
	}
}

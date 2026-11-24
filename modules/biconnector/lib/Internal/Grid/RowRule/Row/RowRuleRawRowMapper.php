<?php

namespace Bitrix\BIConnector\Internal\Grid\RowRule\Row;

use Bitrix\Main\Type\DateTime;

/**
 * Turns rules of the API model into raw rows of the grid. Values stay unescaped: the field assemblers escape them.
 */
final class RowRuleRawRowMapper
{
	/**
	 * @param array[] $items Items of the rule provider `list`.
	 *
	 * @return array[]
	 */
	public static function map(array $items): array
	{
		$rows = [];
		foreach ($items as $item)
		{
			if (!is_array($item) || (int)($item['id'] ?? 0) <= 0)
			{
				continue;
			}

			$rows[] = [
				'ID' => (int)$item['id'],
				'TABLE' => (string)($item['tableName'] ?? ''),
				'FIELD' => (string)($item['fieldName'] ?? ''),
				'CONDITION' => (string)($item['operator'] ?? ''),
				'VALUES' => self::mapValues($item['values'] ?? null),
				'DESCRIPTION' => (string)($item['description'] ?? ''),
				'CREATED_BY_ID' => (int)($item['createdBy']['id'] ?? 0),
				'DATE_CREATE' => self::mapDate($item['dateCreate'] ?? null),
				'UPDATED_BY_ID' => (int)($item['updatedBy']['id'] ?? 0),
				'DATE_MODIFY' => self::mapDate($item['dateModify'] ?? null),
				'IS_USED' => (bool)($item['isUsed'] ?? false),
			];
		}

		return $rows;
	}

	/**
	 * @return string[]
	 */
	private static function mapValues(mixed $values): array
	{
		$titles = [];
		foreach ((array)$values as $value)
		{
			$title = is_array($value) ? ($value['title'] ?? $value['value'] ?? $value['code'] ?? null) : null;
			if (is_scalar($title) && (string)$title !== '')
			{
				$titles[] = (string)$title;
			}
		}

		return $titles;
	}

	private static function mapDate(mixed $value): ?DateTime
	{
		if (!is_string($value) || $value === '')
		{
			return null;
		}

		$timestamp = strtotime($value);

		return $timestamp === false ? null : DateTime::createFromTimestamp($timestamp);
	}
}

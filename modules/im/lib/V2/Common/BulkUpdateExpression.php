<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Common;

use Bitrix\Main\DB\SqlExpression;

/**
 * Helpers for building bulk-update {@see SqlExpression} pieces — typically
 * used together with {@see UpdateByFilterTrait::updateByFilter} to apply
 * different per-row values in a single SQL statement instead of N updates.
 */
class BulkUpdateExpression
{
	/**
	 * Builds a `CASE WHEN id = X THEN Y … END` expression keyed on $idColumn.
	 *
	 * Use with {@see UpdateByFilterTrait::updateByFilter}:
	 *
	 * ```
	 * SomeTable::updateByFilter(
	 *     ['=ID' => array_keys($idToValue)],
	 *     ['SORT' => BulkUpdateExpression::caseById('ID', $idToValue)],
	 * );
	 * ```
	 *
	 * Both id and value are bound as integers (`?i`); string/float variants
	 * can be added when needed. Keys and values from $idToValue are coerced
	 * to int via `(int)` cast — caller may safely pass results of
	 * `array_flip` (which produces string keys for int-castable strings).
	 *
	 * @param array<int|string, int|string> $idToValue map id → new value (both coerced to int)
	 */
	public static function caseById(string $idColumn, array $idToValue): SqlExpression
	{
		if (empty($idToValue))
		{
			throw new \InvalidArgumentException('idToValue must not be empty');
		}

		$clauses = [];
		$args = [];
		foreach ($idToValue as $id => $value)
		{
			$clauses[] = 'WHEN ?# = ?i THEN ?i';
			$args[] = $idColumn;
			$args[] = (int)$id;
			$args[] = (int)$value;
		}
		$sql = 'CASE ' . implode(' ', $clauses) . ' END';

		return new SqlExpression($sql, ...$args);
	}
}

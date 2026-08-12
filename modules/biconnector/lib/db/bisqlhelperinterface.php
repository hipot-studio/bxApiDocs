<?php

namespace Bitrix\BIConnector\DB;

interface BiSqlHelperInterface
{
	/**
	 * Returns SQL expression for the current session timezone.
	 *
	 * @return string
	 */
	public function getSessionTimezoneExpression(): string;

	/**
	 * Returns SQL expression to convert a datetime field from one timezone to another.
	 *
	 * @param string $field Database field or expression.
	 * @param string $fromTimezone Source timezone SQL expression.
	 * @param string $toTimezone Target timezone offset (e.g. '+03:00').
	 *
	 * @return string
	 */
	public function convertTimezone(string $field, string $fromTimezone, string $toTimezone): string;

	/**
	 * Returns SQL aggregate expression that concatenates values within a group.
	 *
	 * @param string $expression Column or expression to concatenate.
	 * @param string $orderBy Optional ORDER BY clause (e.g. 'id ASC'). Empty string means no ordering.
	 * @param string $separator Delimiter between concatenated values.
	 *
	 * @return string
	 */
	public function getGroupConcatExpression(string $expression, string $orderBy = '', string $separator = ','): string;

	/**
	 * Returns SQL expression for the N-th segment of a string split by a delimiter (1-based).
	 *
	 * @param string $field Column or expression to split.
	 * @param string $delimiter Literal delimiter string.
	 * @param int $n 1-based segment index. Must be >= 1.
	 *
	 * @return string
	 * @throws \InvalidArgumentException if $n < 1.
	 */
	public function getSegmentByDelimiter(string $field, string $delimiter, int $n): string;

	/**
	 * Returns SQL expression for the difference in seconds between two datetime expressions (end - start).
	 *
	 * @param string $start Start datetime expression.
	 * @param string $end End datetime expression.
	 *
	 * @return string
	 */
	public function getDateDiffSecondsExpression(string $start, string $end): string;

	/**
	 * Returns SQL expression that casts a text expression to integer.
	 *
	 * On MySQL the expression is returned as-is (implicit numeric coercion makes int=text work natively).
	 * On PostgreSQL wraps with CAST(NULLIF($expr, '') AS integer) to avoid failures on empty strings.
	 *
	 * @param string $expr Column or expression containing a numeric text value.
	 *
	 * @return string
	 */
	public function castToInt(string $expr): string;

	/**
	 * Returns SQL expression that makes an integer expression comparable to a text column.
	 *
	 * On MySQL the expression is returned as-is (implicit coercion keeps the original numeric
	 * comparison unchanged). On PostgreSQL wraps with CAST($expr AS varchar) so that an
	 * int = text comparison becomes a valid text = text comparison.
	 *
	 * @param string $expr Integer column or expression.
	 *
	 * @return string
	 */
	public function castIntToChar(string $expr): string;

	/**
	 * Returns SQL expression that casts a numeric expression to character type for
	 * string operations (e.g. TRIM).
	 *
	 * On MySQL the expression is returned as-is (implicit coercion keeps the original
	 * SQL unchanged). On PostgreSQL wraps with CAST($expr AS varchar) because string
	 * functions like TRIM cannot be applied to numeric columns directly.
	 *
	 * @param string $expr Numeric column or expression.
	 *
	 * @return string
	 */
	public function castNumericToChar(string $expr): string;
}

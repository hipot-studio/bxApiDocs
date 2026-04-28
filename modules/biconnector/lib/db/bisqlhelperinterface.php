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
}

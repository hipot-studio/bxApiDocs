<?php

namespace Bitrix\BIConnector\DB;

class PgsqlSqlHelper extends \Bitrix\Main\DB\PgsqlSqlHelper implements BiSqlHelperInterface
{
	public function getSessionTimezoneExpression(): string
	{
		return "current_setting('TimeZone')";
	}

	public function convertTimezone(string $field, string $fromTimezone, string $toTimezone): string
	{
		return sprintf(
			"(%s::timestamp AT TIME ZONE %s) AT TIME ZONE '%s'",
			$field,
			$fromTimezone,
			$this->forSql($toTimezone)
		);
	}
}

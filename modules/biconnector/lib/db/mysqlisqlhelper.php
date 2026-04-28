<?php

namespace Bitrix\BIConnector\DB;

class MysqliSqlHelper extends \Bitrix\Main\DB\MysqliSqlHelper implements BiSqlHelperInterface
{
	public function getSessionTimezoneExpression(): string
	{
		return '@@session.time_zone';
	}

	public function convertTimezone(string $field, string $fromTimezone, string $toTimezone): string
	{
		return sprintf(
			"CONVERT_TZ(%s, %s, '%s')",
			$field,
			$fromTimezone,
			$this->forSql($toTimezone)
		);
	}
}

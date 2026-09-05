<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Sql;

use Bitrix\Main\Application;
use Bitrix\Main\DB\PgsqlConnection;

final class SqlExpression
{
	public static function fromUnixTime(string $expression): string
	{
		return Application::getConnection() instanceof PgsqlConnection
			? "to_timestamp($expression)"
			: "FROM_UNIXTIME($expression)"
		;
	}
}

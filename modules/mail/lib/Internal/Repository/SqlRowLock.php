<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Repository;

final class SqlRowLock
{
	public static function table(string $tableName, string $databaseType): string
	{
		return $databaseType === 'mssql'
			? $tableName . ' WITH (UPDLOCK, HOLDLOCK)'
			: $tableName;
	}

	public static function suffix(string $databaseType): string
	{
		return $databaseType === 'mssql' ? '' : ' FOR UPDATE';
	}
}

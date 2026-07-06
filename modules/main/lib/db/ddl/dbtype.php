<?php

declare(strict_types=1);

namespace Bitrix\Main\DB\Ddl;

enum DbType
{
	case MySql;
	case PostgreSql;

	public static function getByConnectionType(string $connectionType): self
	{
		return match (strtolower($connectionType))
		{
			'mysql' => self::MySql,
			'pgsql' => self::PostgreSql,
			default => throw new Exception(
				1300,
				'Connection type is not supported',
				['connectionType' => $connectionType],
			),
		};
	}
}

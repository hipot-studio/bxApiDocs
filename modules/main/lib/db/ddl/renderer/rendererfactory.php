<?php

declare(strict_types=1);

namespace Bitrix\Main\DB\Ddl\Renderer;

use Bitrix\Main\DB\Ddl\DbType;

final class RendererFactory
{
	private static ?MysqlRenderer $mysql = null;
	private static ?PgsqlRenderer $pgsql = null;

	public static function get(DbType $dbType): RendererInterface
	{
		return match ($dbType)
		{
			DbType::MySql => self::$mysql ??= new MysqlRenderer(),
			DbType::PostgreSql => self::$pgsql ??= new PgsqlRenderer(),
		};
	}
}

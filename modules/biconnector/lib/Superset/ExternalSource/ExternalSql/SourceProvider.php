<?php

namespace Bitrix\BIConnector\Superset\ExternalSource\ExternalSql;

use Bitrix\BIConnector\ExternalSource;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalSourceRestConnectorTable;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalSourceTable;
use Bitrix\BIConnector\ExternalSource\SourceManager;
use Bitrix\BIConnector\Superset\ExternalSource\ExternalSql;
use Bitrix\Main\Loader;

final class SourceProvider
{
	public static function getExternalSqlTypes(): array
	{
		return [
			ExternalSource\Type::Mysql->value,
			ExternalSource\Type::Pgsql->value,
		];
	}

	/**
	 * @return ExternalSql\Mysql[]|ExternalSql\Pgsql[]
	 */
	public static function getSources(): array
	{
		if (!SourceManager::isExternalSqlConnectionsAvailable())
		{
			return [];
		}

		$connections = ExternalSourceTable::getList([
			'select' => ['TYPE'],
			'filter' => [
				'=ACTIVE' => 'Y',
				'=TYPE' => self::getExternalSqlTypes(),
			],
			'group' => ['TYPE'],
		])
			->fetchAll()
		;

		$connections = array_flip(array_column($connections, 'TYPE'));

		return [
			ExternalSource\Type::Mysql->value => new ExternalSql\Mysql(isset($connections[ExternalSource\Type::Mysql->value])),
			ExternalSource\Type::Pgsql->value => new ExternalSql\Pgsql(isset($connections[ExternalSource\Type::Pgsql->value])),
		];
	}

	/**
	 * Returns cloud placeholder sources for MySQL/PgSQL.
	 * Shows helpdesk article block when no REST connector with matching SOURCE_CODE is registered.
	 *
	 * @return ExternalSql\CloudMysql[]|ExternalSql\CloudPgsql[]
	 */
	public static function getCloudSources(): array
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return [];
		}

		$registeredSourceCodes = ExternalSourceRestConnectorTable::getList([
				'select' => ['SOURCE_CODE'],
				'filter' => [
					'=SOURCE_CODE' => self::getExternalSqlTypes(),
				],
			])
			->fetchAll()
		;

		$registeredSourceCodes = array_flip(array_column($registeredSourceCodes, 'SOURCE_CODE'));

		$result = [];

		if (!isset($registeredSourceCodes[ExternalSource\Type::Mysql->value]))
		{
			$result[ExternalSource\Type::Mysql->value] = new ExternalSql\CloudMysql();
		}

		if (!isset($registeredSourceCodes[ExternalSource\Type::Pgsql->value]))
		{
			$result[ExternalSource\Type::Pgsql->value] = new ExternalSql\CloudPgsql();
		}

		return $result;
	}
}

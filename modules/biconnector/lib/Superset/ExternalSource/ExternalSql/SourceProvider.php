<?php

namespace Bitrix\BIConnector\Superset\ExternalSource\ExternalSql;

use Bitrix\BIConnector\ExternalSource;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalSourceTable;
use Bitrix\BIConnector\ExternalSource\SourceManager;
use Bitrix\BIConnector\Superset\ExternalSource\ExternalSql;

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
	 * @return Source[]
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
}

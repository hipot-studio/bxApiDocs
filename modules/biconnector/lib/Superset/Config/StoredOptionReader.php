<?php

namespace Bitrix\BIConnector\Superset\Config;

use Bitrix\Main\Application;

final class StoredOptionReader
{
	public static function read(string $moduleId, string $name, string $default): string
	{
		$connectionPool = Application::getInstance()->getConnectionPool();
		$connectionPool->useMasterOnly(true);
		try
		{
			$connection = Application::getConnection();
			$helper = $connection->getSqlHelper();
			$value = $connection->queryScalar(
				"SELECT VALUE FROM b_option WHERE MODULE_ID = '{$helper->forSql($moduleId)}'"
				. " AND NAME = '{$helper->forSql($name)}'"
			);
		}
		finally
		{
			$connectionPool->useMasterOnly(false);
		}

		return $value === null ? $default : (string)$value;
	}
}

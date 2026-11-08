<?php
namespace Bitrix\BIConnector\ExternalSource\Source\DB;

use Bitrix\BIConnector;

class ClickhouseConnection extends BIConnector\DB\ClickhouseConnection implements ExternalSqlConnectionInterface
{
	/**
	 * Lists everything selectable in the current database: tables and views alike, the same way
	 * SHOW TABLES does on MySQL.
	 *
	 * @return array
	 * @throws \Bitrix\Main\DB\SqlQueryException
	 */
	public function showTables(): array
	{
		$result = $this->query("
			SELECT name
			FROM system.tables
			WHERE database = currentDatabase()
			ORDER BY name
		");

		$tableList = [];
		while ($table = $result->fetch())
		{
			$tableList[] = $table['name'];
		}

		return $tableList;
	}
}

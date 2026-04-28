<?php
namespace Bitrix\BIConnector\ExternalSource\Source\DB;

use Bitrix\Main;
use Bitrix\BIConnector;

class PgsqlConnection extends BIConnector\DB\PgsqlConnection implements ExternalSqlConnectionInterface
{
	/**
	 * @return array
	 * @throws Main\DB\DuplicateEntryException
	 * @throws Main\DB\SqlQueryException
	 */
	public function showTables(): array
	{
		$result = $this->createResult(
			$this->queryInternal("
				SELECT tablename 
				FROM pg_catalog.pg_tables 
				WHERE schemaname = 'public'
				ORDER BY tablename;
			"),
		);

		$tableList = [];
		while ($table = $result->fetch())
		{
			$tableList[] = array_pop($table);
		}

		return $tableList;
	}
}

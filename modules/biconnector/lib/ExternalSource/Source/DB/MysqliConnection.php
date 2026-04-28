<?php
namespace Bitrix\BIConnector\ExternalSource\Source\DB;

use Bitrix\Main;
use Bitrix\BIConnector;

class MysqliConnection extends BIConnector\DB\MysqliConnection implements ExternalSqlConnectionInterface
{
	/**
	 * @return array
	 * @throws Main\DB\DuplicateEntryException
	 * @throws Main\DB\SqlQueryException
	 */
	public function showTables(): array
	{
		$result = $this->createResult(
			$this->queryInternal("SHOW TABLES"),
		);

		$tableList = [];
		while ($table = $result->fetch())
		{
			$table = array_values($table);
			$tableList[] = $table[0];
		}

		return $tableList;
	}
}

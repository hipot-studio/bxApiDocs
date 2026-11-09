<?php

namespace Bitrix\BIConnector\Superset\Updater\Versions;

use Bitrix\BIConnector\Integration\Superset\Model\SupersetUserTable;
use Bitrix\Main\Application;
use Bitrix\Main\Result;

/**
 * Clears the permission hash of every Superset user, so each user gets roles again on the next visit to BI Builder.
 * Before the strict role lookup a failed lookup could save a partial role list together with a matching hash,
 * and such a user was never synchronized again.
 * UPDATED and CLIENT_ID stay as they are: the first one drives activity, clearing the second one recreates users.
 */
final class Version15 extends BaseVersion
{
	public function run(): Result
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$tableName = $helper->quote(SupersetUserTable::getTableName());
		$hashColumn = $helper->quote('PERMISSION_HASH');
		$connection->queryExecute("UPDATE {$tableName} SET {$hashColumn} = ''");
		SupersetUserTable::cleanCache();

		return new Result();
	}
}

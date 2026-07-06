<?php

namespace Bitrix\Main\UpdateSystem\Migration\Tools;

use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DB\SqlQueryException;

class Database
{
	public static function tableExists(string $tableName): bool
	{
		$connection = Application::getConnection();

		$tableName = preg_replace("/[^A-Za-z0-9%_]+/", '', $tableName);
		if ($tableName == '')
		{
			return false;
		}
		$preparedTableName = strtolower($connection->getSqlHelper()->forSql($tableName));

		$sql = match (mb_strtolower($connection->getType()))
		{
			'mysql' => "SHOW TABLES LIKE '" . $preparedTableName . "'",
			'pgsql' => "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE '" . $preparedTableName . "'"
		};

		return (bool)$connection->query($sql)->fetch();
	}

	public static function columnExists(string $tableName, string $columnName): bool
	{
		$re = '/[^A-Za-z0-9\_]+/';
		$columnName = preg_replace($re, '', $columnName);
		$tableName = preg_replace($re, '', $tableName);
		if (empty($tableName) || empty($columnName))
		{
			return false;
		}

		try
		{
			Application::getConnection()->query(
				new SqlExpression(
					'SELECT ?# FROM ?# WHERE 1 = 0',
					$columnName,
					$tableName,
				)
			);

			return true;
		}
		catch (SqlQueryException)
		{
			return false;
		}
	}

	public static function indexExists(string $tableName, array $indexFields): bool
	{
		return !is_null(self::getIndexName($tableName, $indexFields));
	}

	public static function getIndexName(string $tableName, array $indexFields): ?string
	{
		$indexName = Application::getConnection()->getIndexName($tableName, $indexFields, true);

		return empty($indexName) ? null : $indexName;
	}

	/**
	 * Returns ordered list of column names that form the current primary key.
	 * Empty array if the table has no primary key or doesn't exist.
	 *
	 * @return string[]
	 */
	public static function getPrimaryKeyColumns(string $tableName): array
	{
		$tableName = preg_replace("/[^A-Za-z0-9_]+/", '', $tableName);
		if ($tableName === '')
		{
			return [];
		}

		$connection = Application::getConnection();
		$preparedTableName = $connection->getSqlHelper()->forSql($tableName);

		$sql = match (mb_strtolower($connection->getType()))
		{
			'mysql' =>
				"SELECT COLUMN_NAME"
				. " FROM information_schema.KEY_COLUMN_USAGE"
				. " WHERE TABLE_SCHEMA = DATABASE()"
				. " AND TABLE_NAME = '" . $preparedTableName . "'"
				. " AND CONSTRAINT_NAME = 'PRIMARY'"
				. " ORDER BY ORDINAL_POSITION",
			'pgsql' =>
				"SELECT a.attname AS column_name"
				. " FROM pg_index i"
				. " JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)"
				. " WHERE i.indrelid = '" . $preparedTableName . "'::regclass"
				. " AND i.indisprimary"
				. " ORDER BY array_position(i.indkey, a.attnum)",
		};

		try
		{
			$result = $connection->query($sql);
		}
		catch (SqlQueryException)
		{
			return [];
		}

		$columns = [];
		while ($row = $result->fetch())
		{
			$columns[] = $row['COLUMN_NAME'] ?? $row['column_name'] ?? null;
		}

		return array_values(array_filter($columns));
	}
}

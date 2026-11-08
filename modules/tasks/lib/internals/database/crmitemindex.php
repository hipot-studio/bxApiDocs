<?php

namespace Bitrix\Tasks\Internals\DataBase;

use Bitrix\Main\Application;

/**
 * Supporting index for the "CRM element" task filter over b_utm_tasks_task.
 *
 * b_utm_tasks_task is materialized lazily by the UserField subsystem
 * (CUserTypeEntity::Add -> CreatePropertyTables) the first time a storage-backed
 * field of the TASKS_TASK entity is registered. It therefore can be declared
 * neither in the module schema nor created reliably at one fixed moment, so this
 * idempotent helper is invoked from every path that can observe the table
 * appearing: module install, the versioned updater and the OnAfterUserTypeAdd
 * handler.
 *
 * A concrete element resolves to VALUE = '...', a whole type / all
 * smart-processes to a sargable VALUE LIKE 'prefix%'; both are served by
 * (FIELD_ID, VALUE) instead of scanning every row of the field. Raw,
 * database-specific SQL because the declarative migration API expresses neither
 * the MySQL prefix length (VALUE(50)) nor the PostgreSQL text_pattern_ops
 * opclass required for the LIKE 'prefix%' scan.
 */
final class CrmItemIndex
{
	public const TABLE = 'b_utm_tasks_task';
	public const NAME = 'ix_utm_tasks_task_field_value';

	private const COLUMNS = ['FIELD_ID', 'VALUE'];

	public static function ensure(): void
	{
		$connection = Application::getConnection();

		if (!$connection->isTableExists(self::TABLE))
		{
			return;
		}

		if ($connection->getIndexName(self::TABLE, self::COLUMNS, true) !== null)
		{
			return;
		}

		$connection->query(self::getCreateSql($connection->getType()));
	}

	public static function getCreateSql(string $dbType): string
	{
		return $dbType === 'pgsql'
			? 'CREATE INDEX IF NOT EXISTS ' . self::NAME . ' ON ' . self::TABLE . ' (field_id, value text_pattern_ops)'
			: 'ALTER TABLE ' . self::TABLE . ' ADD INDEX ' . self::NAME . ' (FIELD_ID, VALUE(50))';
	}
}

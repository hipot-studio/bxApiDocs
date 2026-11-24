<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;

final class SharedEditingSchema
{
	private const DOCUMENT_LEGACY_FIELDS = [
		'ID', 'HOST_DOC_KEY', 'KEY_MODE', 'OBJECT_ID', 'VERSION_ID', 'SESSION_TYPE', 'EXTERNAL_LINK_ID',
		'CONTENT_GENERATION', 'EXPECTED_FILE_ID', 'EXPECTED_CONTENT_VERSION', 'EXPECTED_ETAG',
		'LAST_SNAPSHOT_SEQ', 'CONFLICT_OBJECT_ID', 'CONFLICTED', 'UPDATED_TIME', 'NAMESPACE',
	];
	private const SESSION_LEGACY_FIELDS = [
		'ID', 'HOST_DOC_KEY', 'API_SESSION_ID', 'DOCUMENT_SESSION_ID', 'OPEN_TOKEN', 'EDITOR_KEY', 'REV',
		'STATUS', 'CREATE_TIME', 'UPDATE_TIME',
	];
	private const DOCUMENT_FIELDS = ['EDIT_SCOPE_VERSION', 'EDIT_FENCE_STATE', 'TERMINAL_PROOF'];
	private const SESSION_FIELDS = [
		'PLATFORM_USER_ID', 'ACTOR_USER_ID', 'ACCESS_SOURCE_TYPE', 'ACCESS_SOURCE_ID',
		'ACCESS_CONTEXT_HASH', 'ACCESS_CONTEXT', 'ACCESS_EXPIRES_AT', 'ACCESS_REVOKE_STATE', 'REVOKE_RETRY_AT',
	];
	private const INDEXES = [
		'b_disk_vo_document' => [
			['OBJECT_ID', 'EDIT_SCOPE_VERSION', 'SESSION_TYPE', 'VERSION_ID', 'ID'],
			['EDIT_SCOPE_VERSION', 'EDIT_FENCE_STATE', 'ID'],
		],
		'b_disk_vo_session' => [
			['HOST_DOC_KEY', 'PLATFORM_USER_ID', 'ID'],
			['ACCESS_SOURCE_TYPE', 'ACCESS_SOURCE_ID', 'ID'],
			['ACCESS_REVOKE_STATE', 'REVOKE_RETRY_AT', 'ID'],
			['ACCESS_REVOKE_STATE', 'ACCESS_EXPIRES_AT', 'ID'],
		],
	];

	/**
	 * @var \WeakMap<Connection, array{
	 *     document: list<string>, session: list<string>, telemetry: list<string>, indexes: bool
	 * }>|null
	 */
	private static ?\WeakMap $snapshots = null;
	private static ?\WeakMap $sourceRevisionSnapshots = null;
	private static ?\WeakMap $sharedDeliveryHistorySnapshots = null;

	/** Separate from registry readiness: old revoke debt must remain processable during an upgrade. */
	public static function hasSourceRevision(): bool
	{
		$connection = Application::getConnection();
		self::$sourceRevisionSnapshots ??= new \WeakMap();
		if (!isset(self::$sourceRevisionSnapshots[$connection]))
		{
			self::$sourceRevisionSnapshots[$connection] = self::availableFields($connection, 'b_disk_external_link',
				['VIBEOFFICE_REVOKE_REV']) !== [];
		}
		return self::$sourceRevisionSnapshots[$connection];
	}

	/** Missing history indexes defer content collection without disabling access maintenance or legacy cleanup. */
	public static function hasSharedDeliveryHistoryIndexes(): bool
	{
		$connection = Application::getConnection();
		self::$sharedDeliveryHistorySnapshots ??= new \WeakMap();
		if (!isset(self::$sharedDeliveryHistorySnapshots[$connection]))
		{
			$indexes = $connection->isTableExists('b_disk_vibeoffice_delivery')
				? self::readIndexes($connection, 'b_disk_vibeoffice_delivery') : [];
			self::$sharedDeliveryHistorySnapshots[$connection] = in_array(['HOST_DOC_KEY', 'ID'], $indexes, true)
				&& in_array(['EXTERNAL_HASH', 'ID'], $indexes, true);
		}
		return self::$sharedDeliveryHistorySnapshots[$connection];
	}

	public static function hasColumns(): bool
	{
		$snapshot = self::snapshot();

		return $snapshot['document'] === self::DOCUMENT_FIELDS && $snapshot['session'] === self::SESSION_FIELDS;
	}

	public static function isReady(): bool
	{
		return self::hasColumns() && self::snapshot()['indexes'];
	}

	/** @return list<string> Legacy and available additive document columns safe for SELECT. */
	public static function documentFields(): array
	{
		return array_merge(self::DOCUMENT_LEGACY_FIELDS, self::snapshot()['document']);
	}

	/** @return list<string> Legacy and available additive registry columns safe for SELECT. */
	public static function sessionFields(): array
	{
		return array_merge(self::SESSION_LEGACY_FIELDS, self::snapshot()['session'], self::snapshot()['telemetry']);
	}

	public static function hasRevokeTelemetry(): bool
	{
		return self::snapshot()['telemetry'] !== [];
	}

	/** @return array{document: list<string>, session: list<string>, telemetry: list<string>, indexes: bool} */
	private static function snapshot(): array
	{
		$pool = Application::getInstance()->getConnectionPool();
		$connection = $pool->getConnection();
		self::$snapshots ??= new \WeakMap();
		if (isset(self::$snapshots[$connection]))
		{
			return self::$snapshots[$connection];
		}

		// Freeze the gate together with both ORM maps; migrated CLI workers must restart.
		$pool->useMasterOnly(true);
		try
		{
			$document = self::availableFields($connection, 'b_disk_vo_document', self::DOCUMENT_FIELDS);
			$availableSession = self::availableFields($connection, 'b_disk_vo_session',
				array_merge(self::SESSION_FIELDS, ['REVOKE_REQUESTED_AT']));
			$session = array_values(array_intersect(self::SESSION_FIELDS, $availableSession));
			$telemetry = array_values(array_intersect(['REVOKE_REQUESTED_AT'], $availableSession));
			$indexes = $document === self::DOCUMENT_FIELDS && $session === self::SESSION_FIELDS;
			if ($indexes)
			{
				foreach (self::INDEXES as $table => $definitions)
				{
					$availableIndexes = self::readIndexes($connection, $table);
					foreach ($definitions as $columns)
					{
						if (!in_array($columns, $availableIndexes, true))
						{
							$indexes = false;
						}
					}
				}
			}

			return self::$snapshots[$connection] = [
				'document' => $document,
				'session' => $session,
				'telemetry' => $telemetry,
				'indexes' => $indexes,
			];
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}

	private static function availableFields(Connection $connection, string $table, array $fields): array
	{
		if (!$connection->isTableExists($table))
		{
			return [];
		}

		$columns = array_change_key_case($connection->getTableFields($table), CASE_UPPER);

		return array_values(array_filter($fields, static fn(string $field): bool => isset($columns[$field])));
	}

	private static function readIndexes(Connection $connection, string $table): array
	{
		$helper = $connection->getSqlHelper();
		$sql = match ($connection->getType())
		{
			'mysql' => 'SHOW INDEX FROM ' . $helper->quote($table),
			'pgsql' => 'SELECT i.indexrelid AS key_name, k.position AS seq_in_index,'
				. ' a.attname AS column_name, NULL AS sub_part'
				. ' FROM pg_class t JOIN pg_namespace n ON n.oid=t.relnamespace'
				. ' JOIN pg_index i ON i.indrelid=t.oid'
				. ' CROSS JOIN LATERAL unnest(i.indkey) WITH ORDINALITY AS k(attnum, position)'
				. ' JOIN pg_attribute a ON a.attrelid=t.oid AND a.attnum=k.attnum'
				. " WHERE n.nspname='public' AND t.relname='" . $helper->forSql($table) . "'"
				. ' AND i.indisvalid AND i.indisready AND i.indpred IS NULL AND i.indexprs IS NULL',
			default => throw new \LogicException('Unsupported Vibeoffice schema database.'),
		};
		$indexes = [];
		foreach ($connection->query($sql)->fetchAll() as $row)
		{
			$row = array_change_key_case($row, CASE_UPPER);
			$indexes[$row['KEY_NAME']][(int)$row['SEQ_IN_INDEX']] = $row['SUB_PART'] === null
				? strtoupper($row['COLUMN_NAME'])
				: null
			;
		}
		foreach ($indexes as $name => $columns)
		{
			ksort($columns);
			$indexes[$name] = array_values($columns);
		}

		return array_values($indexes);
	}
}

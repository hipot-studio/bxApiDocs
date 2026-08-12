<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Storage;

use Bitrix\Bizproc\Internal\Model\StorageFieldTable;
use Bitrix\Bizproc\Internal\Model\StorageTypeTable;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DB\MysqlCommonConnection;
use Bitrix\Main\DB\SqlHelper;

final class StorageLimitsService
{
	private const MAX_FIELDS_PER_STORAGE = 50;
	private const MAX_STORAGES = 300;
	private const DEFAULT_CLEANUP_DAYS = 90;
	private const OPTION_CLEANUP_DAYS = 'storage_items_cleanup_days';
	private const OPTION_CLEANUP_SIZE_MB = 'storage_items_cleanup_size';

	private const STORAGE_TABLE_NAMES = [
		'b_bp_storage_record_field',
		'b_bp_storage_record_data',
		'b_bp_storage_field',
		'b_bp_storage_type',
	];

	private ?bool $isDiskQuotaReadable = null;

	public function getMaxFieldsPerStorage(): int
	{
		return self::MAX_FIELDS_PER_STORAGE;
	}

	public function getMaxStorages(): int
	{
		return self::MAX_STORAGES;
	}

	public function getCleanupDays(): int
	{
		$days = (int)Option::get('bizproc', self::OPTION_CLEANUP_DAYS, self::DEFAULT_CLEANUP_DAYS);

		return $days > 0 ? $days : self::DEFAULT_CLEANUP_DAYS;
	}

	public function getStorageItemsCleanupSizeBytes(): int
	{
		$sizeMb = (int)Option::get('bizproc', self::OPTION_CLEANUP_SIZE_MB, 0);

		return $sizeMb > 0 ? $sizeMb * 1024 * 1024 : 0;
	}

	public function isDiskQuotaReadable(): bool
	{
		if ($this->isDiskQuotaReadable === null)
		{
			$this->isDiskQuotaReadable = (new \CDiskQuota())->checkDiskQuota(['size' => 0]);
		}

		return $this->isDiskQuotaReadable;
	}

	public function canAddField(int $storageId): bool
	{
		if ($storageId <= 0)
		{
			return true;
		}

		return StorageFieldTable::getFieldsCountByStorage($storageId) < $this->getMaxFieldsPerStorage();
	}

	public function canAddStorage(): bool
	{
		return StorageTypeTable::getCount() < $this->getMaxStorages();
	}

	/**
	 * TODO: задействовать при включении лимита на размер таблиц хранилища
	 * и в будущем агенте очистки (StorageQuotaCleanupAgent). Сейчас метод выставлен на будущее.
	 * @return int
	 */
	public function getStorageTablesSizeBytes(): int
	{
		if (!\Bitrix\Main\ModuleManager::isModuleInstalled('bitrix24'))
		{
			return 0;
		}

		$connection = Application::getConnection();
		if (!($connection instanceof MysqlCommonConnection))
		{
			return 0;
		}

		foreach (['INNODB_TABLESPACES', 'INNODB_SYS_TABLESPACES'] as $metadataTable)
		{
			$size = $this->fetchSizeBytes(
				$connection,
				$this->getMysqlPerTableSizeSql($connection, $metadataTable),
			);

			if ($size !== null)
			{
				return $size;
			}
		}

		return $this->fetchSizeBytes(
			$connection,
			$this->getMysqlInformationSchemaSizeSql($connection),
		) ?? 0;
	}

	private function getMysqlPerTableSizeSql(MysqlCommonConnection $connection, string $metadataTable): string
	{
		$sqlHelper = $connection->getSqlHelper();
		$tablesRef = $sqlHelper->quote('information_schema.tables');
		$metadataRef = $sqlHelper->quote('information_schema.' . $metadataTable);
		$schemaLiteral = "'" . $sqlHelper->forSql($connection->getDatabase()) . "'";
		$tablesList = $this->buildStorageTableNamesList($sqlHelper);

		return "
			SELECT COALESCE(SUM(
				CASE
					WHEN ts.NAME IS NOT NULL
						THEN COALESCE(ts.ALLOCATED_SIZE, ts.FILE_SIZE, 0)
					ELSE
						COALESCE(t.DATA_LENGTH, 0) + COALESCE(t.INDEX_LENGTH, 0)
				END
			), 0) AS SIZE
			FROM {$tablesRef} t
				LEFT JOIN {$metadataRef} ts
					ON ts.NAME = CONCAT(t.TABLE_SCHEMA, '/', t.TABLE_NAME)
			WHERE t.TABLE_SCHEMA = {$schemaLiteral}
				AND t.ENGINE = 'InnoDB'
				AND t.TABLE_NAME IN ({$tablesList})
		";
	}

	private function getMysqlInformationSchemaSizeSql(MysqlCommonConnection $connection): string
	{
		$sqlHelper = $connection->getSqlHelper();
		$tablesRef = $sqlHelper->quote('information_schema.tables');
		$schemaLiteral = "'" . $sqlHelper->forSql($connection->getDatabase()) . "'";
		$tablesList = $this->buildStorageTableNamesList($sqlHelper);

		return "
			SELECT COALESCE(SUM(
				COALESCE(DATA_LENGTH, 0) + COALESCE(INDEX_LENGTH, 0)
			), 0) AS SIZE
			FROM {$tablesRef}
			WHERE TABLE_SCHEMA = {$schemaLiteral}
				AND ENGINE = 'InnoDB'
				AND TABLE_NAME IN ({$tablesList})
		";
	}

	private function buildStorageTableNamesList(SqlHelper $sqlHelper): string
	{
		$items = array_map(
			static fn(string $name) => "'" . $sqlHelper->forSql($name) . "'",
			self::STORAGE_TABLE_NAMES,
		);

		return implode(', ', $items);
	}

	private function fetchSizeBytes(MysqlCommonConnection $connection, string $sql): ?int
	{
		try
		{
			$value = $connection->queryScalar($sql);
		}
		catch (\Throwable)
		{
			return null;
		}

		return (int)($value ?? 0);
	}
}

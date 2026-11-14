<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internals;

use Bitrix\Disk\Internal\Service\UploadCleanup\MarkerStatus;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\Entity;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;

final class UploadCleanupMarkerTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_disk_upload_cleanup_marker';
	}

	public static function getMap(): array
	{
		return [
			'OBJECT_ID' => [
				'data_type' => 'integer',
				'primary' => true,
			],
			'USER_ID' => [
				'data_type' => 'integer',
				'required' => true,
			],
			'PARENT_ID' => [
				'data_type' => 'integer',
				'required' => true,
			],
			'TOKEN_HASH' => [
				'data_type' => 'string',
				'required' => true,
				'validation' => [__CLASS__, 'validateTokenHash'],
			],
			'STATUS' => [
				'data_type' => 'enum',
				'required' => true,
				'values' => array_column(MarkerStatus::cases(), 'value'),
			],
			'EXPIRES_AT' => [
				'data_type' => 'datetime',
				'required' => true,
			],
			'CLAIM_TOKEN' => [
				'data_type' => 'string',
				'validation' => [__CLASS__, 'validateClaimToken'],
			],
			'CLAIMED_AT' => [
				'data_type' => 'datetime',
			],
			'CREATE_TIME' => [
				'data_type' => 'datetime',
				'required' => true,
				'default_value' => static fn(): DateTime => new DateTime(),
			],
			'UPDATE_TIME' => [
				'data_type' => 'datetime',
				'required' => true,
				'default_value' => static fn(): DateTime => new DateTime(),
			],
		];
	}

	public static function validateTokenHash(): array
	{
		return [new Entity\Validator\Length(64, 64)];
	}

	public static function validateClaimToken(): array
	{
		return [new Entity\Validator\Length(null, 64)];
	}

	public static function updateByFilter(
		array|ConditionTree $filter,
		array $data,
		?Connection $connection = null,
	): int
	{
		$entity = self::getEntity();
		$connection ??= $entity->getConnection();
		$helper = $connection->getSqlHelper();
		$where = Query::buildFilterSql($entity, $filter);
		if ($where === '')
		{
			throw new ArgumentException('Updating an upload cleanup marker by an empty filter is not allowed.');
		}

		[$update] = $helper->prepareUpdate(self::getTableName(), $data);
		if ($update === '')
		{
			throw new ArgumentException('Upload cleanup marker update data is empty.');
		}

		$table = $helper->quote(self::getTableName());
		$connection->queryExecute("UPDATE {$table} SET {$update} WHERE {$where}");
		$affectedRows = $connection->getAffectedRowsCount();
		self::cleanCache();

		return $affectedRows;
	}

	public static function deleteByFilter(
		array|ConditionTree $filter,
		?Connection $connection = null,
	): int
	{
		$entity = self::getEntity();
		$connection ??= $entity->getConnection();
		$where = Query::buildFilterSql($entity, $filter);
		if ($where === '')
		{
			throw new ArgumentException('Deleting upload cleanup markers by an empty filter is not allowed.');
		}

		$table = $connection->getSqlHelper()->quote(self::getTableName());
		$connection->queryExecute("DELETE FROM {$table} WHERE {$where}");
		$affectedRows = $connection->getAffectedRowsCount();
		self::cleanCache();

		return $affectedRows;
	}
}

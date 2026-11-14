<?php

namespace Bitrix\Disk\Internals\Index;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\PgsqlConnection;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

/**
 * Class ObjectHeadIndexTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_ObjectHeadIndex_Query query()
 * @method static EO_ObjectHeadIndex_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_ObjectHeadIndex_Result getById($id)
 * @method static EO_ObjectHeadIndex_Result getList(array $parameters = [])
 * @method static EO_ObjectHeadIndex_Entity getEntity()
 * @method static \Bitrix\Disk\Internals\Index\EO_ObjectHeadIndex createObject($setDefaultValues = true)
 * @method static \Bitrix\Disk\Internals\Index\EO_ObjectHeadIndex_Collection createCollection()
 * @method static \Bitrix\Disk\Internals\Index\EO_ObjectHeadIndex wakeUpObject($row)
 * @method static \Bitrix\Disk\Internals\Index\EO_ObjectHeadIndex_Collection wakeUpCollection($rows)
 */
class ObjectHeadIndexTable extends BaseIndexTable
{
	public const OUTCOME_CREATED = 'created';
	public const OUTCOME_UPDATED = 'updated';
	public const OUTCOME_NO_OP = 'no-op';
	public const OUTCOME_SKIPPED = 'skipped';

	public static function getTableName()
	{
		return 'b_disk_object_head_index';
	}

	public static function getMaxIndexSize()
	{
		return Configuration::getMaxHeadIndexSize();
	}

	public static function publishIfChanged($objectId, $searchIndex): Result
	{
		$objectId = (int)$objectId;
		$searchIndex = static::normalizeSearchIndex($searchIndex);

		if (static::updateSearchIndexIfChanged($objectId, $searchIndex) > 0)
		{
			return static::createOutcomeResult(self::OUTCOME_UPDATED);
		}

		$row = static::getCurrentHeadIndexRow($objectId);
		if ($row && $row['SEARCH_INDEX'] === $searchIndex)
		{
			return static::createOutcomeResult(self::OUTCOME_NO_OP);
		}

		try
		{
			$addFailure = static::addWithPostgresqlConflictProtection([
				'OBJECT_ID' => $objectId,
				'SEARCH_INDEX' => $searchIndex,
				'UPDATE_TIME' => new DateTime(),
			]);
		}
		catch (\Throwable $addFailure)
		{
		}

		if ($addFailure instanceof Result && $addFailure->isSuccess())
		{
			if (static::objectStillExists($objectId))
			{
				return static::createOutcomeResult(self::OUTCOME_CREATED);
			}

			// The table has no FK to b_disk_object. If the object was deleted
			// concurrently, its dropIndex() has already run before our insert,
			// so nothing else would ever remove this row.
			static::delete($objectId);

			return static::createOutcomeResult(self::OUTCOME_SKIPPED);
		}

		if (static::updateSearchIndexIfChanged($objectId, $searchIndex) > 0)
		{
			return static::createOutcomeResult(self::OUTCOME_UPDATED);
		}

		$row = static::getCurrentHeadIndexRow($objectId);
		if ($row && $row['SEARCH_INDEX'] === $searchIndex)
		{
			return static::createOutcomeResult(self::OUTCOME_NO_OP);
		}

		if ($addFailure instanceof Result)
		{
			return $addFailure;
		}

		throw $addFailure;
	}

	private static function addWithPostgresqlConflictProtection(array $data): Result
	{
		$connection = Application::getConnection();
		$usesConflictProtection = $connection instanceof PgsqlConnection;
		if ($usesConflictProtection)
		{
			// PostgreSQL aborts the current transaction after a conflicting INSERT.
			// MySQL is intentionally not wrapped: its framework connection rolls back
			// a nested savepoint and then throws "Nested rollbacks are unsupported".
			$connection->startTransaction();
		}

		try
		{
			$result = static::add($data);
		}
		catch (\Throwable $exception)
		{
			if ($usesConflictProtection)
			{
				$connection->rollbackTransaction();
			}

			throw $exception;
		}

		if (!$usesConflictProtection)
		{
			return $result;
		}

		if ($result->isSuccess())
		{
			$connection->commitTransaction();
		}
		else
		{
			$connection->rollbackTransaction();
		}

		return $result;
	}

	public static function updateIfExistsAndChanged($objectId, $searchIndex): Result
	{
		$objectId = (int)$objectId;
		$searchIndex = static::normalizeSearchIndex($searchIndex);

		if (static::updateSearchIndexIfChanged($objectId, $searchIndex) > 0)
		{
			return static::createOutcomeResult(self::OUTCOME_UPDATED);
		}

		$row = static::getCurrentHeadIndexRow($objectId);

		return static::createOutcomeResult($row ? self::OUTCOME_NO_OP : self::OUTCOME_SKIPPED);
	}

	private static function updateSearchIndexIfChanged(int $objectId, string $searchIndex): int
	{
		$fields = [
			'SEARCH_INDEX' => $searchIndex,
			'UPDATE_TIME' => new DateTime(),
		];
		$connection = Application::getConnection();
		if ($connection instanceof PgsqlConnection)
		{
			return static::updateByFilterAndGetAffectedRows(
				$fields,
				[
					'=OBJECT_ID' => $objectId,
					[
						'LOGIC' => 'OR',
						'!=SEARCH_INDEX' => $searchIndex,
						'=SEARCH_INDEX' => null,
					],
				],
			);
		}

		$sqlHelper = $connection->getSqlHelper();
		[$update, $binds] = $sqlHelper->prepareUpdate(static::getTableName(), $fields);
		$searchIndexHash = $sqlHelper->forSql(sha1($searchIndex));
		$sql = 'UPDATE ' . static::getTableName() . ' SET ' . $update
			. " WHERE OBJECT_ID = {$objectId}"
			. " AND (SEARCH_INDEX IS NULL OR {$sqlHelper->getSha1Function('SEARCH_INDEX')} != '{$searchIndexHash}')";
		$connection->queryExecute($sql, $binds);

		return $connection->getAffectedRowsCount();
	}

	private static function getCurrentHeadIndexRow(int $objectId): array|false
	{
		$connection = Application::getConnection();
		if ($connection instanceof PgsqlConnection)
		{
			return static::getList([
				'select' => ['OBJECT_ID', 'SEARCH_INDEX'],
				'filter' => ['=OBJECT_ID' => $objectId],
				'limit' => 1,
			])->fetch();
		}

		$sql = 'SELECT OBJECT_ID, SEARCH_INDEX FROM ' . static::getTableName()
			. " WHERE OBJECT_ID = {$objectId} FOR UPDATE";

		return $connection->query($sql)->fetch();
	}

	private static function objectStillExists(int $objectId): bool
	{
		// Inside an outer REPEATABLE READ transaction a plain read returns the
		// snapshot taken before a concurrent delete, so the check must be a
		// locking current read.
		$sql = 'SELECT ID FROM ' . ObjectTable::getTableName()
			. " WHERE ID = {$objectId} FOR UPDATE";

		$connection = Application::getConnection();
		if (!($connection instanceof PgsqlConnection))
		{
			// MySQL is intentionally not wrapped in a nested transaction: its
			// framework connection throws on nested rollbacks.
			return (bool)$connection->query($sql)->fetch();
		}

		// PostgreSQL REPEATABLE READ turns a locking read of a concurrently
		// changed row into a serialization failure that aborts the whole
		// transaction, so the read runs under a savepoint; the just-created
		// head row is compensated before the original error is rethrown.
		$connection->startTransaction();
		try
		{
			$row = $connection->query($sql)->fetch();
		}
		catch (\Throwable $exception)
		{
			$connection->rollbackTransaction();
			static::delete($objectId);

			throw $exception;
		}
		$connection->commitTransaction();

		return (bool)$row;
	}

	private static function createOutcomeResult(string $outcome): Result
	{
		$result = new Result();
		$result->setData(['outcome' => $outcome]);

		return $result;
	}
}

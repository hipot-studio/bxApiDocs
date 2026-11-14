<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Common;

use Bitrix\Im\V2\Logger;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\ORM\Query\Query;

/**
 * Forces the tail index of a message on MySQL.
 *
 * The optimizer estimates a message by the average number of rows per MESSAGE_ID, so for a message
 * with many views or reactions it reads the table backwards by the primary key instead of the
 * composite index. Details and measurements: im/.ai/knowledge-base/notes/message-tail-index-plan.md
 */
final class MySqlIndexHint
{
	/** The negative verdict is kept for the whole cluster, so a reset is a directory of its own. */
	public const MISSING_INDEX_CACHE_DIR = '/bx/im/v2/index-hint/';

	/**
	 * Short enough for the hint to come back on its own once the updater has added the index, long
	 * enough to keep a portal with a broken DDL from a guaranteed failing query on every tail.
	 */
	private const MISSING_INDEX_CACHE_TTL = 300;

	/** MySQL ER_KEY_DOES_NOT_EXITS: the forced index is not on the table. */
	private const ERROR_KEY_DOES_NOT_EXIST = 1176;

	/** @var array<string, true> */
	private static array $missingIndexes = [];

	/**
	 * Runs the query for its ID column only, forcing the given index. The passed query is switched
	 * to selecting ID.
	 *
	 * @return int[]
	 */
	public static function fetchIds(Query $query, string $indexName): array
	{
		$entity = $query->getEntity();
		$connection = $entity->getConnection();
		$query->setSelect(['ID']);
		$sql = $query->getQuery();
		$tableName = $entity->getDBTableName();

		if (self::isIndexMissing($tableName, $indexName))
		{
			return self::fetchIdsBySql($connection, $sql);
		}

		$hintedSql = self::addHint($query, $sql, $indexName);
		if ($hintedSql === $sql)
		{
			return self::fetchIdsBySql($connection, $sql);
		}

		try
		{
			return self::fetchIdsBySql($connection, $hintedSql);
		}
		catch (SqlQueryException $exception)
		{
			// The index is declared in the install schema and added by the updater, but a portal whose
			// DDL has not gone through must keep answering: the plan degrades, the tail still works.
			// Only the missing index is answered that way. Another failure - a lock wait of an online
			// build, for one - says nothing about the index, and turning the hint off for everyone
			// after it would leave the tail on the slow plan while the index is in place.
			if (self::errorCode($exception) !== self::ERROR_KEY_DOES_NOT_EXIST)
			{
				throw $exception;
			}

			self::rememberMissingIndex($tableName, $indexName);
			(new Logger())->log('MySqlIndexHint: unable to use ' . $indexName . ' on ' . $tableName);

			return self::fetchIdsBySql($connection, $sql);
		}
	}

	/**
	 * The driver code of the failure. MySQL prints it in front of the database message
	 * (`(1176) Key '...' doesn't exist in table '...'`), and the exception carries no other copy of it.
	 */
	private static function errorCode(SqlQueryException $exception): ?int
	{
		if (preg_match('/^\((\d+)\)/', $exception->getDatabaseMessage(), $matches) !== 1)
		{
			return null;
		}

		return (int)$matches[1];
	}

	/**
	 * The verdict outlives the request: without that, every tail of a portal with a missing index
	 * would send the failing query again, handle the exception and only then answer.
	 */
	private static function isIndexMissing(string $tableName, string $indexName): bool
	{
		$key = self::cacheKey($tableName, $indexName);
		if (isset(self::$missingIndexes[$key]))
		{
			return true;
		}

		if (!Cache::createInstance()->initCache(self::MISSING_INDEX_CACHE_TTL, $key, self::MISSING_INDEX_CACHE_DIR))
		{
			return false;
		}

		self::$missingIndexes[$key] = true;

		return true;
	}

	private static function rememberMissingIndex(string $tableName, string $indexName): void
	{
		$key = self::cacheKey($tableName, $indexName);
		self::$missingIndexes[$key] = true;

		$cache = Cache::createInstance();
		if ($cache->startDataCache(self::MISSING_INDEX_CACHE_TTL, $key, self::MISSING_INDEX_CACHE_DIR))
		{
			$cache->endDataCache(true);
		}
	}

	private static function addHint(Query $query, string $sql, string $indexName): string
	{
		$entity = $query->getEntity();
		$sqlHelper = $entity->getConnection()->getSqlHelper();
		$tableSource = 'FROM ' . $sqlHelper->quote($entity->getDBTableName())
			. ' ' . $sqlHelper->quote($query->getInitAlias());
		$hintedSql = str_replace(
			$tableSource,
			$tableSource . ' FORCE INDEX (' . $sqlHelper->quote($indexName) . ')',
			$sql,
			$replacementCount,
		);

		if ($replacementCount === 1)
		{
			return $hintedSql;
		}

		// The hint tunes the plan and is not a part of the contract: a query whose printed FROM no
		// longer matches is still executed, just without the hint, so the feature keeps working.
		(new Logger())->log(
			'MySqlIndexHint: unable to force ' . $indexName . ' on ' . $entity->getDBTableName(),
		);

		return $sql;
	}

	/**
	 * @return int[]
	 */
	private static function fetchIdsBySql(Connection $connection, string $sql): array
	{
		return array_map('intval', array_column($connection->query($sql)->fetchAll(), 'ID'));
	}

	private static function cacheKey(string $tableName, string $indexName): string
	{
		return $tableName . '.' . $indexName;
	}
}

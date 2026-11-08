<?php

namespace Bitrix\Mail;

use Bitrix\Mail\Helper\Mailbox;
use Bitrix\Mail\Internal\Service\SourceGeneration\GenerationScope;
use Bitrix\Mail\Internals\MailboxDirectoryTable;
use Bitrix\Main\Entity\ExpressionField;
use Bitrix\Main\Entity\Query;
use Bitrix\Main\Result;

class MailboxDirectory
{
	/** @var array<int, array<string, array>> mailbox id => scope cache key => dirs by path */
	private static array $mailboxDirs = [];

	/**
	 * @var array<int, array<string, GenerationScope>> The scope behind every cached entry above.
	 *      An addressed reset asks the scope itself which generations its entry covers: the key
	 *      is a string built for uniqueness, and reading a shape back out of it went wrong once
	 *      already - the shape stating what it refuses carries no generation number at all.
	 */
	private static array $mailboxDirScopes = [];

	/**
	 * The scope of a read that named none: the generations serving the mailbox, which for a
	 * mailbox without a pointer means every one of them except the ones it is preparing. The
	 * folders of a prepared generation are written when its snapshot is accepted, before the
	 * pass that moves the pointer, and they belong to no tree the user is looking at.
	 */
	private static function scope($mailboxId, ?GenerationScope $scope): GenerationScope
	{
		return $scope ?? GenerationScope::servingMailbox((int)$mailboxId);
	}

	public static function fetchAllDirsTypes($mailboxId, ?GenerationScope $scope = null)
	{
		return MailboxDirectoryTable::getList([
			'filter' => self::scope($mailboxId, $scope)->apply([
				'LOGIC'       => 'AND',
				'=MAILBOX_ID' => $mailboxId,
				[
					'LOGIC'       => 'OR',
					'=IS_OUTCOME' => MailboxDirectoryTable::ACTIVE,
					'=IS_TRASH'   => MailboxDirectoryTable::ACTIVE,
					'=IS_SPAM'    => MailboxDirectoryTable::ACTIVE,
				]
			]),
			'select' => ['*'],
			'order'  => ['LEVEL' => 'ASC']
		])->fetchCollection();
	}

	public static function fetchOneLevelByParentId($mailboxId, $id, $level, ?GenerationScope $scope = null)
	{
		$query = MailboxDirectoryTable::getList([
			'filter' => self::scope($mailboxId, $scope)->apply([
				'LOGIC'       => 'AND',
				'=MAILBOX_ID' => $mailboxId,
				'=PARENT_ID'  => $id,
				'=LEVEL'      => $level,
			]),
			'select' => ['*']
		]);

		$result = [];

		while ($row = $query->fetchObject())
		{
			$result[$row->getPath()] = $row;
		}

		return $result;
	}

	public static function fetchAllLevelByParentId($mailboxId, $path, $level, ?GenerationScope $scope = null)
	{
		$query = MailboxDirectoryTable::getList([
			'filter' => self::scope($mailboxId, $scope)->apply([
				'LOGIC'       => 'AND',
				'=MAILBOX_ID' => $mailboxId,
				'%=PATH'      => $path,
				'>=LEVEL'     => $level,
			]),
			'select' => ['*'],
			'order'  => ['LEVEL' => 'ASC']
		]);

		$result = [];

		while ($row = $query->fetchObject())
		{
			$result[$row->getPath()] = $row;
		}

		return $result;
	}

	public static function fetchOneByMailboxIdAndHash($mailboxId, $hash, ?GenerationScope $scope = null)
	{
		return MailboxDirectoryTable::getList([
			'filter' => self::scope($mailboxId, $scope)->apply([
				'=MAILBOX_ID' => $mailboxId,
				'=DIR_MD5'    => $hash
			])
		])->fetchObject();
	}

	public static function fetchOneOutcome($mailboxId, ?GenerationScope $scope = null)
	{
		return MailboxDirectoryTable::getList([
			'filter' => self::scope($mailboxId, $scope)->apply([
				'=MAILBOX_ID' => $mailboxId,
				'=IS_OUTCOME' => MailboxDirectoryTable::ACTIVE
			])
		])->fetchObject();
	}

	public static function fetchTrashAndSpamHash($mailboxId, ?GenerationScope $scope = null)
	{
		$query = MailboxDirectoryTable::getList([
			'filter' => self::scope($mailboxId, $scope)->apply([
				'LOGIC'       => 'AND',
				'=MAILBOX_ID' => $mailboxId,
				[
					'LOGIC'     => 'OR',
					'=IS_TRASH' => MailboxDirectoryTable::ACTIVE,
					'=IS_SPAM'  => MailboxDirectoryTable::ACTIVE,
				]
			])
		]);

		$result = [];

		while ($row = $query->fetch())
		{
			$result[] = $row['DIR_MD5'];
		}

		return $result;
	}

	public static function fetchOneById($id)
	{
		return MailboxDirectoryTable::getList([
			'filter' => [
				'=ID' => $id
			]
		])->fetchObject();
	}

	public static function fetchOneByHash($mailboxId, $hash, ?GenerationScope $scope = null)
	{
		return MailboxDirectoryTable::getList([
			'filter' => self::scope($mailboxId, $scope)->apply([
				'=MAILBOX_ID' => $mailboxId,
				'=DIR_MD5'    => $hash
			])
		])->fetchObject();
	}

	public static function updateSync($id, $val)
	{
		return MailboxDirectoryTable::update(
			$id,
			[
				'IS_SYNC' => $val
			]
		);
	}

	public static function resetDirsTypes($mailboxId, $type, ?GenerationScope $scope = null)
	{
		$entity = MailboxDirectoryTable::getEntity();
		$connection = $entity->getConnection();

		$query = sprintf(
			'UPDATE %s SET %s WHERE %s',
			$connection->getSqlHelper()->quote($entity->getDbTableName()),
			$connection->getSqlHelper()->prepareUpdate($entity->getDbTableName(), [
				$type => MailboxDirectoryTable::INACTIVE,
			])[0],
			Query::buildFilterSql(
				$entity,
				self::scope($mailboxId, $scope)->apply([
					'MAILBOX_ID' => $mailboxId,
					$type        => MailboxDirectoryTable::ACTIVE,
				])
			)
		);

		return $connection->query($query);
	}

	public static function update($id, $data)
	{
		return MailboxDirectoryTable::update($id, $data);
	}

	public static function add(array $data)
	{
		return MailboxDirectoryTable::add($data);
	}

	public static function addMulti($rows, $ignoreEvents = false)
	{
		return MailboxDirectoryTable::addMulti($rows, $ignoreEvents);
	}

	public static function deleteList(array $filter)
	{
		$entity = MailboxDirectoryTable::getEntity();
		$connection = $entity->getConnection();

		return $connection->query(sprintf(
			'DELETE FROM %s WHERE %s',
			$connection->getSqlHelper()->quote($entity->getDbTableName()),
			Query::buildFilterSql($entity, $filter)
		));
	}

	public static function updateSyncDirs(array $values, $val, $mailboxId, ?GenerationScope $scope = null)
	{
		$entity = MailboxDirectoryTable::getEntity();
		$connection = $entity->getConnection();

		$totalValues = count($values);
		$batchSize = 100;
		$offset = 0;

		while ($offset < $totalValues)
		{
			$batchValues = array_slice($values, $offset, $batchSize);
			$offset += $batchSize;

			$result = $connection->query(sprintf(
				"UPDATE %s SET %s WHERE %s",
				$connection->getSqlHelper()->quote($entity->getDbTableName()),
				$connection->getSqlHelper()->prepareUpdate($entity->getDbTableName(), [
					'IS_SYNC' => $val,
				])[0],
				Query::buildFilterSql(
					$entity,
					self::scope($mailboxId, $scope)->apply([
						'=MAILBOX_ID' => $mailboxId,
						'@DIR_MD5'    => $batchValues,
						'IS_DISABLED' => MailboxDirectoryTable::INACTIVE,
					])
				)
			));
		}

		return $result ?? new Result();
	}

	public static function fetchAll(int $mailboxId, ?GenerationScope $scope = null)
	{
		$scope = self::scope($mailboxId, $scope);
		$cacheKey = $scope->getCacheKey();

		if (!isset(self::$mailboxDirs[$mailboxId][$cacheKey]))
		{
			$query = MailboxDirectoryTable::getList([
				'filter' => $scope->apply([
					'=MAILBOX_ID' => $mailboxId
				]),
				'select' => ['*'],
				/*
					When assembling directories, we look for their parents.
					Sorting ensures that for a directories that parents are processed first,
					and for children, matching parents are always found from those processed.
				 */
				'order'  => ['LEVEL' => 'ASC']
			]);

			$result = [];

			while ($row = $query->fetchObject())
			{
				$result[$row->getPath()] = $row;
			}

			self::$mailboxDirs[$mailboxId][$cacheKey] = $result;
			self::$mailboxDirScopes[$mailboxId][$cacheKey] = $scope;
		}

		return self::$mailboxDirs[$mailboxId][$cacheKey];
	}

	public static function invalidateCache(?int $mailboxId = null, ?int $generationId = null): void
	{
		if ($mailboxId === null)
		{
			self::$mailboxDirs = [];
			self::$mailboxDirScopes = [];
			GenerationScope::invalidateActivePointerCache();

			return;
		}

		if ($generationId === null)
		{
			unset(self::$mailboxDirs[$mailboxId], self::$mailboxDirScopes[$mailboxId]);
			GenerationScope::invalidateActivePointerCache($mailboxId);

			return;
		}

		// Reset the cache of one generation only: drop the entries whose scope covers it
		foreach (array_keys(self::$mailboxDirs[$mailboxId] ?? []) as $cacheKey)
		{
			if (self::entryCoversGeneration($mailboxId, $cacheKey, $generationId))
			{
				unset(self::$mailboxDirs[$mailboxId][$cacheKey], self::$mailboxDirScopes[$mailboxId][$cacheKey]);
			}
		}
	}

	/**
	 * Whether the entry of the cache holds the folders of this generation, asked of the scope
	 * that built the entry. An entry whose scope is unknown is dropped: a cache entry lost for
	 * nothing costs one query, a stale one is read as the truth.
	 */
	private static function entryCoversGeneration(int $mailboxId, string $cacheKey, int $generationId): bool
	{
		return (self::$mailboxDirScopes[$mailboxId][$cacheKey] ?? null)?->includes($generationId) ?? true;
	}

	public static function fetchAllSyncDirs($mailboxId, ?GenerationScope $scope = null)
	{
		return MailboxDirectoryTable::getList([
			'filter' => self::scope($mailboxId, $scope)->apply([
				'=MAILBOX_ID'  => $mailboxId,
				'=IS_SYNC'     => MailboxDirectoryTable::ACTIVE,
				'=IS_DISABLED' => MailboxDirectoryTable::INACTIVE
			]),
			'select' => ['*'],
			'order'  => ['LEVEL' => 'ASC']
		])->fetchCollection();
	}

	public static function fetchAllDisabledDirs($mailboxId, ?GenerationScope $scope = null)
	{
		return MailboxDirectoryTable::getList([
			'filter' => self::scope($mailboxId, $scope)->apply([
				'=MAILBOX_ID'  => $mailboxId,
				'=IS_DISABLED' => MailboxDirectoryTable::ACTIVE
			]),
			'select' => ['*'],
			'order'  => ['ID' => 'ASC']
		])->fetchCollection();
	}

	public static function countMessagesSyncDirs($mailboxId, ?GenerationScope $scope = null)
	{
		$counter = MailboxDirectoryTable::getList([
			'filter'  => self::scope($mailboxId, $scope)->apply([
				'=MAILBOX_ID'  => $mailboxId,
				'=IS_SYNC'     => MailboxDirectoryTable::ACTIVE,
				'=IS_DISABLED' => MailboxDirectoryTable::INACTIVE
			]),
			'select'  => ['CNT'],
			'runtime' => [
				new ExpressionField('CNT', 'SUM(%s)', 'MESSAGE_COUNT'),
			]
		])->fetch();

		return (int)$counter['CNT'];
	}

	public static function getMinSyncTime($mailboxId, ?GenerationScope $scope = null)
	{
		$res = MailboxDirectoryTable::getList([
			'filter'  => self::scope($mailboxId, $scope)->apply([
				'=MAILBOX_ID' => $mailboxId,
				'=IS_SYNC'    => MailboxDirectoryTable::ACTIVE,
			]),
			'select'  => ['MIN_SYNC_TIME'],
			'runtime' => [
				new ExpressionField('MIN_SYNC_TIME', 'MIN(COALESCE(%s, 0))', 'SYNC_TIME'),
			]
		])->fetch();

		return (int)$res['MIN_SYNC_TIME'];
	}

	public static function countSyncDirs($mailboxId, ?GenerationScope $scope = null)
	{
		$counter = MailboxDirectoryTable::getList([
			'filter'  => self::scope($mailboxId, $scope)->apply([
				'=MAILBOX_ID'  => $mailboxId,
				'=IS_SYNC'     => MailboxDirectoryTable::ACTIVE,
				'=IS_DISABLED' => MailboxDirectoryTable::INACTIVE
			]),
			'select'  => ['CNT'],
			'runtime' => [
				new ExpressionField('CNT', 'COUNT(*)'),
			]
		])->fetch();

		return (int)$counter['CNT'];
	}

	public static function updateMessageCount($id, $val)
	{
		return MailboxDirectoryTable::update(
			$id,
			[
				'MESSAGE_COUNT' => $val
			]
		);
	}

	public static function updateFlags($id, $flags)
	{
		return MailboxDirectoryTable::update(
			$id,
			[
				'FLAGS' => $flags
			]
		);
	}

	public static function updateSyncTime($id, $val)
	{
		return MailboxDirectoryTable::update(
			$id,
			[
				'SYNC_TIME' => $val
			]
		);
	}

	public static function setSyncLock(int $id, int $time)
	{
		$entity = MailboxDirectoryTable::getEntity();
		$connection = $entity->getConnection();

		$query = sprintf(
			"UPDATE %s SET %s WHERE %s",
			$connection->getSqlHelper()->quote($entity->getDbTableName()),
			$connection->getSqlHelper()->prepareUpdate($entity->getDbTableName(), [
				'SYNC_LOCK' => $time,
			])[0],
			Query::buildFilterSql(
				$entity,
				[
					'=ID' => $id,
					[
						'LOGIC'      => 'OR',
						'=SYNC_LOCK' => 'IS NULL',
						'<SYNC_LOCK' => time() - Mailbox::getTimeout(),
					]
				]
			)
		);

		$connection->query($query);
		$count = $connection->getAffectedRowsCount();

		return $count;
	}
}

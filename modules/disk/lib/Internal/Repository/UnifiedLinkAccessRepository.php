<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Repository;

use Bitrix\Disk\Internal\
{Model\UnifiedLinkAccessTable};
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Main\Application;
use Bitrix\Disk\Document\Vibeoffice\DocumentStateRepository;
use Bitrix\Disk\Document\Vibeoffice\UnifiedLinkSourceRevision;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Internals\ObjectPathTable;
use Bitrix\Disk\Internal\Service\Audit\AuditTransaction;
use Bitrix\Disk\Internal\Service\Audit\AuditTransactionOutcome;
use Bitrix\Disk\Internal\Service\Audit\AuditTransactionException;
use Bitrix\Main\Error;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;

class UnifiedLinkAccessRepository
{
	/**
	 * The level belongs to the object and not to whoever asks, while the access check asks it once per user.
	 * Both writers below drop the entry, so a level read after a change is still the stored one.
	 *
	 * The entry lives as long as the process does, and a change made by another process does not reach it.
	 * That costs nothing today: the table is written only by {@see self::set()} and {@see self::deleteByObjectId()},
	 * and every reader of the level is a web request, which ends long before a level is changed elsewhere.
	 * A long-lived reader - an agent or a consumer of a queue asking for the same object twice - would need
	 * a lifetime bound to something shorter than the process.
	 *
	 * @var array<int, UnifiedLinkAccessLevel|null> Levels already read in this process; null is a stored answer too.
	 */
	private static array $levelsByObjectId = [];

	/**
	 * @param int $objectId
	 * @return UnifiedLinkAccessLevel|null
	 * @throws ArgumentException
	 * @throws SystemException
	 * @throws ObjectPropertyException
	 */
	public static function getByObjectId(int $objectId): ?UnifiedLinkAccessLevel
	{
		if (array_key_exists($objectId, self::$levelsByObjectId))
		{
			return self::$levelsByObjectId[$objectId];
		}

		$result = UnifiedLinkAccessTable::query()
			->setSelect(['ACCESS_LEVEL'])
			->where('OBJECT_ID', $objectId)
			->setLimit(1)
			->fetch()
		;

		$level = isset($result['ACCESS_LEVEL']) && is_string($result['ACCESS_LEVEL'])
			? UnifiedLinkAccessLevel::tryFrom($result['ACCESS_LEVEL'])
			: null
		;

		self::$levelsByObjectId[$objectId] = $level;

		return $level;
	}

	/**
	 * @param array<int, int> $objectIds
	 * @return array<int, UnifiedLinkAccessLevel>
	 * @throws ArgumentException
	 * @throws SystemException
	 * @throws ObjectPropertyException
	 */
	public static function getByObjectIds(array $objectIds): array
	{
		$objectIds = array_values(array_unique(array_filter(array_map('intval', $objectIds))));

		if (empty($objectIds))
		{
			return [];
		}

		$accessLevels = [];

		$queryResult = UnifiedLinkAccessTable::query()
			->setSelect(['OBJECT_ID', 'ACCESS_LEVEL'])
			->whereIn('OBJECT_ID', $objectIds)
			->exec()
		;

		while ($row = $queryResult->fetch())
		{
			$accessLevel = UnifiedLinkAccessLevel::tryFrom((string)$row['ACCESS_LEVEL']);

			if ($accessLevel instanceof UnifiedLinkAccessLevel)
			{
				$accessLevels[(int)$row['OBJECT_ID']] = $accessLevel;
			}
		}

		return $accessLevels;
	}

	/**
	 * @param int $objectId
	 * @param UnifiedLinkAccessLevel $level
	 * @return AddResult
	 */
	public static function set(int $objectId, UnifiedLinkAccessLevel $level): AddResult
	{
		unset(self::$levelsByObjectId[$objectId]);
		return self::mutate(static function () use ($objectId, $level): AddResult {
			if (!self::lockObject($objectId))
			{
				return (new AddResult())->addError(new Error('The unified link object no longer exists.'));
			}
			$connection = Application::getConnection();
			$helper = $connection->getSqlHelper();
			$connection->queryExecute('INSERT INTO ' . UnifiedLinkAccessTable::getTableName()
				. ' (' . $helper->quote('OBJECT_ID') . ', ' . $helper->quote('ACCESS_LEVEL') . ') VALUES (' . $objectId . ", '')"
				. ($connection->getType() === 'pgsql' ? ' ON CONFLICT (' . $helper->quote('OBJECT_ID') . ') DO NOTHING' : ' ON DUPLICATE KEY UPDATE ID = ID'));
			$old = self::lockAccess($objectId);
			if ($old === null) { throw new \RuntimeException('The unified link access row is unavailable.'); }
			if ($old['ACCESS_LEVEL'] !== $level->value)
			{
				$updated = UnifiedLinkAccessTable::update($old['ID'], ['ACCESS_LEVEL' => $level->value]);
				if (!$updated->isSuccess()) { return (new AddResult())->addErrors($updated->getErrors()); }
				UnifiedLinkSourceRevision::advanceLocked($objectId);
				$old['ACCESS_LEVEL'] = $level->value;
			}
			$result = new AddResult();
			$result->setId((int)$old['ID']);
			$result->setData(['OBJECT_ID' => $objectId, 'ACCESS_LEVEL' => $level->value]);
			$result->setObject(UnifiedLinkAccessTable::wakeUpObject($old));
			return $result;
		});
	}

	public static function deleteByObjectId(int $objectId): void
	{
		unset(self::$levelsByObjectId[$objectId]);
		$result = self::mutate(static function () use ($objectId): AddResult {
			$exists = self::lockObject($objectId);
			$connection = Application::getConnection();
			$connection->queryExecute('DELETE FROM ' . UnifiedLinkAccessTable::getTableName()
				. ' WHERE OBJECT_ID = ' . $objectId);
			if ($exists && (int)$connection->getAffectedRowsCount() > 0) { UnifiedLinkSourceRevision::advanceLocked($objectId); }
			return new AddResult();
		});
		if (!$result->isSuccess()) { throw new \RuntimeException(implode('; ', $result->getErrorMessages())); }
	}

	private static function lockObject(int $objectId): bool
	{
		return (bool)DocumentStateRepository::fetchForUpdate(ObjectTable::query()->setSelect(['ID'])->where('ID', $objectId));
	}

	private static function lockAccess(int $objectId): ?array
	{
		$row = DocumentStateRepository::fetchForUpdate(UnifiedLinkAccessTable::query()->setSelect(['ID', 'OBJECT_ID', 'ACCESS_LEVEL'])
			->where('OBJECT_ID', $objectId));
		return $row ?: null;
	}

	private static function mutate(callable $callback): AddResult
	{
		$connection = Application::getConnection();
		$result = new AddResult();
		if (AuditTransaction::isActive($connection))
		{
			AuditTransaction::run(static function () use ($connection, $callback, &$result): AddResult|false {
				$savepoint = 'disk_unified_' . bin2hex(random_bytes(8));
				$connection->queryExecute('SAVEPOINT ' . $savepoint);
				try
				{
					$result = $callback();
					if (!$result->isSuccess()) { $connection->queryExecute('ROLLBACK TO SAVEPOINT ' . $savepoint); }
					$connection->queryExecute('RELEASE SAVEPOINT ' . $savepoint);
				}
				catch (\Throwable $exception)
				{
					try { $connection->queryExecute('ROLLBACK TO SAVEPOINT ' . $savepoint); }
					catch (\Throwable) { /* A server-side rollback may already have removed the savepoint. */ }
					try { $connection->queryExecute('RELEASE SAVEPOINT ' . $savepoint); }
					catch (\Throwable) { /* Preserve the mutation failure for the transaction owner. */ }
					throw $exception;
				}
				return $result->isSuccess() ? $result : false;
			}, $connection);
			return $result;
		}
		$owner = new AuditTransaction($connection);
		$operation = static function () use ($callback, &$result): AuditTransactionOutcome {
			$result = $callback();
			return $result->isSuccess() ? AuditTransactionOutcome::success($result) : AuditTransactionOutcome::failure($result);
		};
		$outcome = $owner->hasOpenDatabaseTransaction()
			? $owner->executeInsideSavepoint($operation) : $owner->executeRoot($operation);
		if (!$outcome->isSuccess())
		{
			if (!$outcome->isRollbackConfirmed()) { throw new AuditTransactionException($outcome); }
			if ($outcome->getCallbackException()) { throw $outcome->getCallbackException(); }
		}
		return $result;
	}

	/** Fresh document transactions keep nonlocking reads; older external snapshots need current reads. */
	public static function sourceSnapshot(array $objectIds, bool $bypassOuterRepeatableRead = false): array
	{
		$bypassOuterRepeatableRead = $bypassOuterRepeatableRead && DocumentStateRepository::shouldBypassRepeatableRead();
		$objectIds = array_values(array_unique(array_filter(array_map('intval', $objectIds), static fn(int $id): bool => $id > 0)));
		sort($objectIds, SORT_NUMERIC);
		if (!$objectIds) { return ['paths' => [], 'levels' => [], 'ids' => []]; }
		$pathQuery = ObjectPathTable::query()->setSelect(['OBJECT_ID', 'PARENT_ID', 'DEPTH_LEVEL'])->whereIn('OBJECT_ID', $objectIds)
			->setOrder(['OBJECT_ID' => 'ASC', 'PARENT_ID' => 'ASC']);
		if (!$bypassOuterRepeatableRead)
		{
			$paths = [];
			foreach ($objectIds as $id) { $paths[$id] = [$id => $id]; }
			foreach ($pathQuery->fetchAll() as $row) { $paths[(int)$row['OBJECT_ID']][(int)$row['PARENT_ID']] = (int)$row['PARENT_ID']; }
			$ids = [];
			foreach ($paths as &$path)
			{
				ksort($path, SORT_NUMERIC);
				$path = array_values($path);
				foreach ($path as $id) { $ids[$id] = null; }
			}
			unset($path);
			ksort($ids, SORT_NUMERIC);
			$query = UnifiedLinkAccessTable::query()->setSelect(['OBJECT_ID', 'ACCESS_LEVEL'])->whereIn('OBJECT_ID', array_keys($ids))
				->setOrder(['OBJECT_ID' => 'ASC']);
			foreach ($query->fetchAll() as $row) { $ids[(int)$row['OBJECT_ID']] = UnifiedLinkAccessLevel::tryFrom((string)$row['ACCESS_LEVEL']); }
			return ['paths' => $paths, 'levels' => $ids, 'ids' => array_keys($ids)];
		}

		$discoveredRows = DocumentStateRepository::discoverRowsForFreshRead($pathQuery);
		if ($discoveredRows === null)
		{
			return [
				'paths' => array_fill_keys($objectIds, []),
				'levels' => array_fill_keys($objectIds, UnifiedLinkAccessLevel::Denied),
				'ids' => [],
			];
		}
		$candidateIds = [];
		foreach ($objectIds as $id) { $candidateIds[$id] = $id; }
		foreach ($discoveredRows as $row)
		{
			$parentId = (int)($row['PARENT_ID'] ?? 0);
			if ($parentId > 0) { $candidateIds[$parentId] = $parentId; }
		}
		$orderedCandidateIds = DocumentStateRepository::orderObjectIdsTopologically($candidateIds, $discoveredRows);
		$live = [];
		foreach (DocumentStateRepository::lockObjectsForShare($orderedCandidateIds, ['ID', 'DELETED_TYPE']) as $row)
		{
			if ((int)$row['DELETED_TYPE'] === ObjectTable::DELETED_TYPE_NONE) { $live[(int)$row['ID']] = true; }
		}
		$paths = [];
		foreach ($objectIds as $id) { $paths[$id] = [$id => $id]; }
		foreach (DocumentStateRepository::fetchAllForShare($pathQuery) as $row)
		{
			$objectId = (int)$row['OBJECT_ID'];
			$parentId = (int)$row['PARENT_ID'];
			if ($parentId > 0 && isset($paths[$objectId])) { $paths[$objectId][$parentId] = $parentId; }
		}
		$coveredIds = [];
		$unavailable = [];
		foreach ($paths as $objectId => &$path)
		{
			ksort($path, SORT_NUMERIC);
			$path = array_values($path);
			foreach ($path as $id)
			{
				if (!isset($live[$id]))
				{
					$path = [];
					$unavailable[$objectId] = UnifiedLinkAccessLevel::Denied;
					break;
				}
			}
			foreach ($path as $id) { $coveredIds[$id] = null; }
		}
		unset($path);
		ksort($coveredIds, SORT_NUMERIC);
		$ids = array_keys($coveredIds);
		$levels = $coveredIds;
		if ($ids !== [])
		{
			$query = UnifiedLinkAccessTable::query()->setSelect(['OBJECT_ID', 'ACCESS_LEVEL'])->whereIn('OBJECT_ID', $ids)
				->setOrder(['OBJECT_ID' => 'ASC']);
			foreach (DocumentStateRepository::fetchAllForShare($query) as $row)
			{
				$levels[(int)$row['OBJECT_ID']] = UnifiedLinkAccessLevel::tryFrom((string)$row['ACCESS_LEVEL']);
			}
		}
		$levels = array_replace($levels, $unavailable);

		return ['paths' => $paths, 'levels' => $levels, 'ids' => $ids];
	}
}

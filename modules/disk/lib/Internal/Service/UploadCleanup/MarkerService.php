<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UploadCleanup;

use Bitrix\Disk\Internals\UploadCleanupMarkerTable;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\Security\Random;
use Bitrix\Main\Type\DateTime;
use Throwable;

final class MarkerService
{
	public const ERROR_FILE_IS_BEING_REMOVED = 'The file is being removed.';

	public const CLAIM_LEASE_SECONDS = 15 * 60;
	public const TOMBSTONE_GRACE_SECONDS = 24 * 60 * 60;

	/** @var \WeakMap<Connection, bool>|null */
	private static ?\WeakMap $availabilityByConnection = null;
	/** @var array<int, array{objectId: int, available: bool}> */
	private static array $confirmedRevokedScopes = [];
	/** @var array<int, bool> */
	private static array $markerPresenceOverrides = [];
	private static int $nextScopeId = 0;

	public function __construct(
		private readonly ?Connection $connection = null,
	)
	{
	}

	public function isAvailable(): bool
	{
		$connection = $this->getConnection();
		self::$availabilityByConnection ??= new \WeakMap();
		if (isset(self::$availabilityByConnection[$connection]))
		{
			return self::$availabilityByConnection[$connection];
		}

		return self::$availabilityByConnection[$connection] = $connection->isTableExists(
			UploadCleanupMarkerTable::getTableName(),
		);
	}

	public function hasMarker(int $objectId): bool
	{
		return
			$objectId > 0
			&& $this->isAvailable()
			&& UploadCleanupMarkerTable::getByPrimary($objectId, ['select' => ['OBJECT_ID']])->fetch() !== false;
	}

	/**
	 * @param array{f: int, u: int, d: int, exp: int} $carrier
	 */
	public function issue(array $carrier, string $token): bool
	{
		if (
			!$this->isAvailable()
			|| $token === ''
			|| $carrier['f'] <= 0
			|| $carrier['u'] <= 0
			|| $carrier['d'] <= 0
			|| $carrier['exp'] <= time()
		)
		{
			return false;
		}

		$connection = $this->getConnection();
		$now = new DateTime();
		$connection->startTransaction();
		try
		{
			$connection->add(UploadCleanupMarkerTable::getTableName(), [
				'OBJECT_ID' => $carrier['f'],
				'USER_ID' => $carrier['u'],
				'PARENT_ID' => $carrier['d'],
				'TOKEN_HASH' => self::hashToken($token),
				'STATUS' => MarkerStatus::Pending->value,
				'EXPIRES_AT' => DateTime::createFromTimestamp($carrier['exp']),
				'CREATE_TIME' => $now,
				'UPDATE_TIME' => $now,
			], null);
			$this->persistMarkerPresence([$carrier['f']], true, $connection);
			$connection->commitTransaction();
		}
		catch (Throwable)
		{
			$connection->rollbackTransaction();

			return false;
		}

		$this->refreshPersistenceState([$carrier['f']], true);

		return true;
	}

	/**
	 * Claims an upload for recovery, issuing a server-side marker for a fresh unmarked file when needed.
	 *
	 * @internal
	 */
	public function claimOrIssueRecovery(int $objectId, int $userId, int $parentId): ?string
	{
		if ($objectId <= 0 || $userId <= 0 || $parentId <= 0 || !$this->isAvailable())
		{
			return null;
		}

		$connection = $this->getConnection();
		$claimToken = null;
		$now = new DateTime();
		$connection->startTransaction();
		try
		{
			$marker = $this->loadMarkerRow($objectId, $connection);
			if ($marker !== null)
			{
				$marker = $this->loadMarkerRow($objectId, $connection, true);
				if (
					$marker === null
					|| (int)$marker['USER_ID'] !== $userId
					|| (int)$marker['PARENT_ID'] !== $parentId
				)
				{
					$connection->rollbackTransaction();

					return null;
				}

				if ($marker['STATUS'] === MarkerStatus::Pending->value)
				{
					$claimToken = Random::getString(64, true);
					$affected = UploadCleanupMarkerTable::updateByFilter([
						'=OBJECT_ID' => $objectId,
						'=USER_ID' => $userId,
						'=PARENT_ID' => $parentId,
						'=STATUS' => MarkerStatus::Pending->value,
					], [
						'STATUS' => MarkerStatus::Deleting->value,
						'CLAIM_TOKEN' => $claimToken,
						'CLAIMED_AT' => $now,
						'UPDATE_TIME' => $now,
					], $connection);
					if ($affected !== 1)
					{
						$connection->rollbackTransaction();

						return null;
					}
				}
				elseif ($marker['STATUS'] === MarkerStatus::Deleting->value)
				{
					$claimToken = (string)$marker['CLAIM_TOKEN'];
					if ($claimToken === '')
					{
						$connection->rollbackTransaction();

						return null;
					}
					UploadCleanupMarkerTable::updateByFilter([
						'=OBJECT_ID' => $objectId,
						'=USER_ID' => $userId,
						'=PARENT_ID' => $parentId,
						'=STATUS' => MarkerStatus::Deleting->value,
						'=CLAIM_TOKEN' => $claimToken,
					], [
						'CLAIMED_AT' => $now,
						'UPDATE_TIME' => $now,
					], $connection);
				}
				else
				{
					$connection->rollbackTransaction();

					return null;
				}
			}
			else
			{
				$claimToken = Random::getString(64, true);
				$connection->add(UploadCleanupMarkerTable::getTableName(), [
					'OBJECT_ID' => $objectId,
					'USER_ID' => $userId,
					'PARENT_ID' => $parentId,
					'TOKEN_HASH' => self::hashToken(Random::getString(64, true)),
					'STATUS' => MarkerStatus::Deleting->value,
					'EXPIRES_AT' => $now,
					'CLAIM_TOKEN' => $claimToken,
					'CLAIMED_AT' => $now,
					'CREATE_TIME' => $now,
					'UPDATE_TIME' => $now,
				], null);

				$file = $this->loadRecoveryFileRow($objectId, $connection);
				if (
					$file === null
					|| (int)$file['CREATED_BY'] !== $userId
					|| (int)$file['PARENT_ID'] !== $parentId
					|| (int)$file['TYPE'] !== ObjectTable::TYPE_FILE
					|| (int)$file['REAL_OBJECT_ID'] !== $objectId
					|| (int)$file['DELETED_TYPE'] !== ObjectTable::DELETED_TYPE_NONE
				)
				{
					$connection->rollbackTransaction();

					return null;
				}
			}

			$this->persistMarkerPresence([$objectId], true, $connection);
			$connection->commitTransaction();
		}
		catch (Throwable)
		{
			$connection->rollbackTransaction();

			return null;
		}

		$this->refreshPersistenceState([$objectId], true);

		return $claimToken;
	}

	/**
	 * @param array{f: int, u: int, d: int, exp: int} $carrier
	 */
	public function claim(array $carrier, string $token): ?string
	{
		if (!$this->isAvailable() || $token === '')
		{
			return null;
		}

		$claimToken = Random::getString(64, true);
		$now = new DateTime();
		$affected = UploadCleanupMarkerTable::updateByFilter([
			'=OBJECT_ID' => $carrier['f'],
			'=USER_ID' => $carrier['u'],
			'=PARENT_ID' => $carrier['d'],
			'=TOKEN_HASH' => self::hashToken($token),
			'=STATUS' => MarkerStatus::Pending->value,
			'>EXPIRES_AT' => $now,
		], [
			'STATUS' => MarkerStatus::Deleting->value,
			'CLAIM_TOKEN' => $claimToken,
			'CLAIMED_AT' => $now,
			'UPDATE_TIME' => $now,
		], $this->getConnection());

		if ($affected === 1)
		{
			self::$markerPresenceOverrides[$carrier['f']] = true;

			return $claimToken;
		}

		return null;
	}

	public function revokeClaim(int $objectId, string $claimToken): bool
	{
		return $this->finishClaim($objectId, $claimToken, MarkerStatus::Revoked);
	}

	public function consumeClaim(int $objectId, string $claimToken): bool
	{
		return $this->finishClaim($objectId, $claimToken, MarkerStatus::Consumed);
	}

	public function recoverStaleClaim(
		int $objectId,
		string $claimToken,
		MarkerStatus $status = MarkerStatus::Deleting,
	): ?string
	{
		if (
			$objectId <= 0
			|| $claimToken === ''
			|| !in_array($status, [MarkerStatus::Deleting, MarkerStatus::PublicationDeleting], true)
			|| !$this->isAvailable()
		)
		{
			return null;
		}

		$now = new DateTime();
		$staleBefore = DateTime::createFromTimestamp(time() - self::CLAIM_LEASE_SECONDS);
		$newClaimToken = Random::getString(64, true);
		$affected = UploadCleanupMarkerTable::updateByFilter([
			'=OBJECT_ID' => $objectId,
			'=STATUS' => $status->value,
			'=CLAIM_TOKEN' => $claimToken,
			'<CLAIMED_AT' => $staleBefore,
		], [
			'CLAIM_TOKEN' => $newClaimToken,
			'CLAIMED_AT' => $now,
			'UPDATE_TIME' => $now,
		], $this->getConnection());

		return $affected === 1 ? $newClaimToken : null;
	}

	public function revokeExpiredPending(int $limit = 200): int
	{
		if (!$this->isAvailable() || $limit <= 0)
		{
			return 0;
		}

		$now = new DateTime();
		$ids = UploadCleanupMarkerTable::getList([
			'select' => ['OBJECT_ID'],
			'filter' => [
				'=STATUS' => MarkerStatus::Pending->value,
				'<=EXPIRES_AT' => $now,
			],
			'order' => ['EXPIRES_AT' => 'ASC', 'OBJECT_ID' => 'ASC'],
			'limit' => $limit,
		])->fetchAll();
		$ids = array_map('intval', array_column($ids, 'OBJECT_ID'));
		if ($ids === [])
		{
			return 0;
		}

		$affected = UploadCleanupMarkerTable::updateByFilter([
			'@OBJECT_ID' => $ids,
			'=STATUS' => MarkerStatus::Pending->value,
			'<=EXPIRES_AT' => $now,
		], [
			'STATUS' => MarkerStatus::Revoked->value,
			'UPDATE_TIME' => $now,
		], $this->getConnection());
		$this->clearMarkerPresenceForStatuses($ids, [MarkerStatus::Revoked]);

		return $affected;
	}

	/**
	 * Confirms an idempotent replay after a proved cleanup already deleted the file.
	 *
	 * @param array{f: int, u: int, d: int, exp: int} $carrier
	 */
	public function matchesConsumedProof(array $carrier, string $token): bool
	{
		if (
			!$this->isAvailable()
			|| $token === ''
			|| $carrier['f'] <= 0
			|| $carrier['u'] <= 0
			|| $carrier['d'] <= 0
		)
		{
			return false;
		}

		$row = UploadCleanupMarkerTable::getList([
			'select' => ['OBJECT_ID'],
			'filter' => [
				'=OBJECT_ID' => $carrier['f'],
				'=USER_ID' => $carrier['u'],
				'=PARENT_ID' => $carrier['d'],
				'=TOKEN_HASH' => self::hashToken($token),
				'=STATUS' => MarkerStatus::Consumed->value,
			],
			'limit' => 1,
		])->fetch();

		return $row !== false;
	}

	public function revokeBeforeUse(int $objectId, ?bool $hasMarker = null): bool
	{
		if ($objectId <= 0)
		{
			return true;
		}
		if ($this->consumeConfirmedRevokedScope($objectId))
		{
			return true;
		}
		if (!$this->shouldInspectMarker($objectId, $hasMarker))
		{
			return true;
		}
		if (!$this->isAvailable())
		{
			return true;
		}

		$row = UploadCleanupMarkerTable::getByPrimary($objectId, [
			'select' => ['STATUS'],
		])->fetch();
		if (!$row)
		{
			$this->setMarkerPresence([$objectId], false);

			return true;
		}

		if ($row['STATUS'] === MarkerStatus::Pending->value)
		{
			$affected = UploadCleanupMarkerTable::updateByFilter([
				'=OBJECT_ID' => $objectId,
				'=STATUS' => MarkerStatus::Pending->value,
			], [
				'STATUS' => MarkerStatus::Revoked->value,
				'UPDATE_TIME' => new DateTime(),
			], $this->getConnection());
			if ($affected === 1)
			{
				$this->setMarkerPresence([$objectId], false);

				return true;
			}

			$row = UploadCleanupMarkerTable::getByPrimary($objectId, [
				'select' => ['STATUS'],
			])->fetch();
		}

		if (!$row || $row['STATUS'] === MarkerStatus::Revoked->value)
		{
			$this->setMarkerPresence([$objectId], false);

			return true;
		}

		return false;
	}

	public function markerPresenceHint(int $objectId, bool $persistedValue): bool
	{
		return self::$markerPresenceOverrides[$objectId] ?? $persistedValue;
	}

	/**
	 * @param array<int, array{userId: int, parentId: int}> $objects Identity keyed by object ID.
	 */
	public function revokeBatchBeforeUse(array $objects, ?array &$confirmedRevokedObjectIds = null): bool
	{
		$transitionedObjectIds = [];
		$result = $this->transitionBatchBeforeUse(
			$objects,
			MarkerStatus::Revoked,
			null,
			$confirmedRevokedObjectIds,
			$transitionedObjectIds,
		);
		if ($result)
		{
			$this->setMarkerPresence($transitionedObjectIds, false);
		}
		else
		{
			$confirmedRevokedObjectIds = [];
		}

		return $result;
	}

	/**
	 * @param array<int, array{userId: int, parentId: int}> $objects Identity keyed by object ID.
	 * @param array<int, string>|null $publicationClaims Claim token keyed by object ID.
	 */
	public function claimBatchBeforePublication(
		array $objects,
		?array &$confirmedObjectIds = null,
		?array &$publicationClaims = null,
	): bool
	{
		$claimToken = Random::getString(64, true);
		$transitionedObjectIds = [];
		$result = $this->transitionBatchBeforeUse(
			$objects,
			MarkerStatus::Publishing,
			$claimToken,
			$confirmedObjectIds,
			$transitionedObjectIds,
		);
		if (!$result)
		{
			$confirmedObjectIds = [];
			$publicationClaims = [];

			return false;
		}

		$publicationClaims = array_fill_keys($transitionedObjectIds, $claimToken);
		foreach ($transitionedObjectIds as $objectId)
		{
			self::$markerPresenceOverrides[$objectId] = true;
		}

		return $result;
	}

	/**
	 * @param array<int, array{userId: int, parentId: int}> $objects
	 * @param int[]|null $confirmedObjectIds
	 * @param int[]|null $transitionedObjectIds
	 */
	private function transitionBatchBeforeUse(
		array $objects,
		MarkerStatus $pendingTarget,
		?string $claimToken,
		?array &$confirmedObjectIds,
		?array &$transitionedObjectIds,
	): bool
	{
		$confirmedObjectIds = [];
		$transitionedObjectIds = [];
		if ($objects === [] || !$this->isAvailable())
		{
			return true;
		}

		foreach ($objects as $objectId => $identity)
		{
			if (
				!is_array($identity)
				|| (int)$objectId <= 0
				|| (int)($identity['userId'] ?? 0) <= 0
				|| (int)($identity['parentId'] ?? 0) <= 0
			)
			{
				return false;
			}
		}
		ksort($objects, SORT_NUMERIC);

		$connection = $this->getConnection();
		$connection->startTransaction();
		try
		{
			$ids = implode(',', array_map('intval', array_keys($objects)));
			$table = $connection->getSqlHelper()->quote(UploadCleanupMarkerTable::getTableName());
			$rows = $connection->query(
				"SELECT OBJECT_ID, USER_ID, PARENT_ID, STATUS FROM {$table}"
				. " WHERE OBJECT_ID IN ({$ids}) ORDER BY OBJECT_ID FOR UPDATE",
			)->fetchAll();

			$now = new DateTime();
			foreach ($rows as $row)
			{
				$objectId = (int)$row['OBJECT_ID'];
				$identity = $objects[$objectId] ?? null;
				if (
					$identity === null
					|| (int)$row['USER_ID'] !== $identity['userId']
					|| (int)$row['PARENT_ID'] !== $identity['parentId']
					|| !in_array($row['STATUS'], [MarkerStatus::Pending->value, MarkerStatus::Revoked->value], true)
				)
				{
					$connection->rollbackTransaction();

					return false;
				}
				$confirmedObjectIds[] = $objectId;

				if ($row['STATUS'] === MarkerStatus::Pending->value)
				{
					$update = [
						'STATUS' => $pendingTarget->value,
						'UPDATE_TIME' => $now,
					];
					if ($pendingTarget === MarkerStatus::Publishing)
					{
						$update['CLAIM_TOKEN'] = $claimToken;
						$update['CLAIMED_AT'] = $now;
					}
					$affected = UploadCleanupMarkerTable::updateByFilter([
						'=OBJECT_ID' => $objectId,
						'=STATUS' => MarkerStatus::Pending->value,
					], $update, $connection);
					if ($affected !== 1)
					{
						$connection->rollbackTransaction();

						return false;
					}
					$transitionedObjectIds[] = $objectId;
				}
			}

			$connection->commitTransaction();

			return true;
		}
		catch (Throwable $exception)
		{
			$connection->rollbackTransaction();

			throw $exception;
		}
	}

	/** @param array<int, string> $publicationClaims */
	public function commitPublicationClaims(array $publicationClaims): bool
	{
		$byToken = [];
		foreach ($publicationClaims as $objectId => $claimToken)
		{
			if ((int)$objectId <= 0 || !is_string($claimToken) || $claimToken === '')
			{
				return false;
			}
			$byToken[$claimToken][] = (int)$objectId;
		}

		foreach ($byToken as $claimToken => $objectIds)
		{
			$affected = UploadCleanupMarkerTable::updateByFilter([
				'@OBJECT_ID' => $objectIds,
				'=STATUS' => MarkerStatus::Publishing->value,
				'=CLAIM_TOKEN' => $claimToken,
			], [
				'STATUS' => MarkerStatus::Revoked->value,
				'CLAIM_TOKEN' => null,
				'CLAIMED_AT' => null,
				'UPDATE_TIME' => new DateTime(),
			], $this->getConnection());
			if ($affected !== count($objectIds))
			{
				return false;
			}
			$this->setMarkerPresence($objectIds, false);
		}

		return true;
	}

	public function claimPublicationRollback(int $objectId, string $claimToken): bool
	{
		if ($objectId <= 0 || $claimToken === '' || !$this->isAvailable())
		{
			return false;
		}

		return UploadCleanupMarkerTable::updateByFilter([
			'=OBJECT_ID' => $objectId,
			'=STATUS' => MarkerStatus::Publishing->value,
			'=CLAIM_TOKEN' => $claimToken,
		], [
			'STATUS' => MarkerStatus::PublicationDeleting->value,
			'CLAIMED_AT' => new DateTime(),
			'UPDATE_TIME' => new DateTime(),
		], $this->getConnection()) === 1;
	}

	public function queueStalePublicationsForRecovery(int $limit = 200): int
	{
		if (!$this->isAvailable() || $limit <= 0)
		{
			return 0;
		}

		$staleBefore = DateTime::createFromTimestamp(time() - self::CLAIM_LEASE_SECONDS);
		$ids = UploadCleanupMarkerTable::query()
			->setSelect(['OBJECT_ID'])
			->where('STATUS', MarkerStatus::Publishing->value)
			->where('CLAIMED_AT', '<', $staleBefore)
			->setOrder(['CLAIMED_AT' => 'ASC', 'OBJECT_ID' => 'ASC'])
			->setLimit($limit)
			->exec()
			->fetchAll()
		;
		$ids = array_map('intval', array_column($ids, 'OBJECT_ID'));
		if ($ids === [])
		{
			return 0;
		}

		$affected = UploadCleanupMarkerTable::updateByFilter([
			'@OBJECT_ID' => $ids,
			'=STATUS' => MarkerStatus::Publishing->value,
			'<CLAIMED_AT' => $staleBefore,
		], [
			'STATUS' => MarkerStatus::PublicationDeleting->value,
			'UPDATE_TIME' => new DateTime(),
		], $this->getConnection());

		return $affected;
	}

	public static function hashToken(string $token): string
	{
		return hash('sha256', $token);
	}

	public function runWithConfirmedRevokedMarker(int $objectId, callable $operation): mixed
	{
		$scopeId = ++self::$nextScopeId;
		self::$confirmedRevokedScopes[$scopeId] = [
			'objectId' => $objectId,
			'available' => true,
		];

		try
		{
			return $operation();
		}
		finally
		{
			unset(self::$confirmedRevokedScopes[$scopeId]);
		}
	}

	private function finishClaim(int $objectId, string $claimToken, MarkerStatus $status): bool
	{
		if ($objectId <= 0 || $claimToken === '' || !$this->isAvailable())
		{
			return false;
		}

		$finished = UploadCleanupMarkerTable::updateByFilter([
			'=OBJECT_ID' => $objectId,
			'@STATUS' => [MarkerStatus::Deleting->value, MarkerStatus::PublicationDeleting->value],
			'=CLAIM_TOKEN' => $claimToken,
		], [
			'STATUS' => $status->value,
			'CLAIM_TOKEN' => null,
			'CLAIMED_AT' => null,
			'UPDATE_TIME' => new DateTime(),
		], $this->getConnection()) === 1;
		if ($finished)
		{
			$this->setMarkerPresence([$objectId], $status === MarkerStatus::Consumed);
		}

		return $finished;
	}

	private function getConnection(): Connection
	{
		return $this->connection ?? Application::getConnection();
	}

	private function consumeConfirmedRevokedScope(int $objectId): bool
	{
		foreach (array_reverse(array_keys(self::$confirmedRevokedScopes)) as $scopeId)
		{
			$scope = self::$confirmedRevokedScopes[$scopeId];
			if ($scope['available'] && $scope['objectId'] === $objectId)
			{
				self::$confirmedRevokedScopes[$scopeId]['available'] = false;

				return true;
			}
		}

		return false;
	}

	private function shouldInspectMarker(int $objectId, ?bool $hasMarker): bool
	{
		if (array_key_exists($objectId, self::$markerPresenceOverrides))
		{
			return self::$markerPresenceOverrides[$objectId];
		}

		return $hasMarker ?? true;
	}

	/** @param int[] $objectIds */
	private function setMarkerPresence(array $objectIds, bool $hasMarker): bool
	{
		$objectIds = array_values(array_unique(array_filter(
			array_map('intval', $objectIds),
			static fn(int $objectId): bool => $objectId > 0,
		)));
		if ($objectIds === [])
		{
			return true;
		}

		try
		{
			$this->persistMarkerPresence($objectIds, $hasMarker, $this->getConnection());
		}
		catch (Throwable)
		{
			return false;
		}
		$this->refreshPersistenceState($objectIds, $hasMarker);

		return true;
	}

	/** @param int[] $objectIds */
	private function persistMarkerPresence(array $objectIds, bool $hasMarker, Connection $connection): void
	{
		$helper = $connection->getSqlHelper();
		[$update] = $helper->prepareUpdate(ObjectTable::getTableName(), [
			'HAS_UPLOAD_CLEANUP_MARKER' => $hasMarker ? 1 : 0,
		]);
		$table = $helper->quote(ObjectTable::getTableName());
		$ids = implode(',', array_map('intval', $objectIds));
		$connection->queryExecute("UPDATE {$table} SET {$update} WHERE ID IN ({$ids})");
	}

	/** @param int[] $objectIds */
	private function refreshPersistenceState(array $objectIds, bool $hasMarker): void
	{
		UploadCleanupMarkerTable::cleanCache();
		ObjectTable::cleanCache();
		foreach ($objectIds as $objectId)
		{
			self::$markerPresenceOverrides[$objectId] = $hasMarker;
		}
	}

	private function loadMarkerRow(int $objectId, Connection $connection, bool $lock = false): ?array
	{
		$table = $connection->getSqlHelper()->quote(UploadCleanupMarkerTable::getTableName());
		$sql = "SELECT OBJECT_ID, USER_ID, PARENT_ID, STATUS, CLAIM_TOKEN FROM {$table}"
			. " WHERE OBJECT_ID = {$objectId}";
		if ($lock)
		{
			$sql .= ' FOR UPDATE';
		}
		$row = $connection->query($sql)->fetch();

		return $row === false ? null : $row;
	}

	private function loadRecoveryFileRow(int $objectId, Connection $connection): ?array
	{
		$table = $connection->getSqlHelper()->quote(ObjectTable::getTableName());
		$row = $connection->query(
			"SELECT ID, CREATED_BY, PARENT_ID, TYPE, REAL_OBJECT_ID, DELETED_TYPE FROM {$table}"
			. " WHERE ID = {$objectId} FOR UPDATE",
		)->fetch();

		return $row === false ? null : $row;
	}

	/**
	 * @param int[] $objectIds
	 * @param MarkerStatus[] $statuses
	 */
	private function clearMarkerPresenceForStatuses(array $objectIds, array $statuses): void
	{
		$rows = UploadCleanupMarkerTable::query()
			->setSelect(['OBJECT_ID'])
			->whereIn('OBJECT_ID', $objectIds)
			->whereIn('STATUS', array_map(static fn(MarkerStatus $status): string => $status->value, $statuses))
			->exec()
		;
		$terminalObjectIds = [];
		while ($row = $rows->fetch())
		{
			$terminalObjectIds[] = (int)$row['OBJECT_ID'];
		}
		$this->setMarkerPresence($terminalObjectIds, false);
	}
}

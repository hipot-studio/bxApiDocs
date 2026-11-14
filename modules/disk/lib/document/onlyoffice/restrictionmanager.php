<?php

namespace Bitrix\Disk\Document\OnlyOffice;

use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\RestrictionLog;
use Bitrix\Disk\Document\Models\RestrictionLogTable;
use Bitrix\Disk\Document\OnlyOffice\Cloud\LimitInfo;
use Bitrix\Disk\Driver;
use Bitrix\Disk\Integration\Baas\BaasSessionBoostService;
use Bitrix\Disk\Integration\Bitrix24Manager;
use Bitrix\Disk\Internal\Service\Environment;
use Bitrix\Main\Application;
use Bitrix\Main\Config;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Disk\Document\Models\DocumentSessionTable;
use RuntimeException;
use Throwable;

final class RestrictionManager
{
	public const DEFAULT_LIMIT = 10;
	public const UNLIMITED_VALUE = 10_000_000;
	public const LOCK_NAME = 'oo_edit_restriction';
	public const TTL = 4 * 3600;
	public const TTL_VIBEOFFICE = 10 * 60;
	public const GRACE_VIBEOFFICE = 3 * 60;
	public const TTL_PENDING = 2*60;
	public const SAVE_SESSION_EVENT = 'OnSaveSessionInRestrictionLog';
	public const DELETE_SESSION_EVENT = 'OnDeleteSessionsFromRestrictionLog';

	protected const LOCK_LIMIT = 15;

	protected Config\Configuration $config;
	protected Environment $environment;

	private bool $eventsDeferred = false;
	/** @var array<int, array{type: string, availableCount: int|null}> */
	private array $deferredEvents = [];
	private bool $limitMemoizationEnabled = false;
	private ?int $memoizedLimit = null;

	public function __construct()
	{
		$this->config = Config\Configuration::getInstance('disk');
		$this->environment = ServiceLocator::getInstance()->get(Environment::class);
	}

	public function shouldUseRestriction(): bool
	{
		if ($this->environment->isCloudPortal())
		{
			return $this->shouldUseRestrictionForCloud();
		}

		return $this->shouldUseRestrictionForBox();
	}

	/**
	 * Remembers the limit for the rest of the decision, once the caller has asked for it.
	 *
	 * Off by default: the component of OnlyOffice reads the limit before the lock and again under it, and
	 * both readings are meant to be independent. It is asked for by a caller holding one instance for one
	 * decision ({@see \Bitrix\Disk\Document\Vibeoffice\EditSessionAdmission::admit()}), where the source
	 * would be consulted twice for the same answer.
	 */
	public function enableLimitMemoization(): void
	{
		$this->limitMemoizationEnabled = true;
	}

	public function disableLimitMemoization(): void
	{
		$this->limitMemoizationEnabled = false;
		$this->memoizedLimit = null;
	}

	/**
	 * A failure of the source is not remembered: a temporarily unreachable limit frozen for the rest of the
	 * request would refuse every opening that follows it.
	 */
	public function getLimit(): int
	{
		if ($this->limitMemoizationEnabled && $this->memoizedLimit !== null)
		{
			return $this->memoizedLimit;
		}

		$limit = $this->resolveLimit();

		if ($this->limitMemoizationEnabled)
		{
			$this->memoizedLimit = $limit;
		}

		return $limit;
	}

	private function resolveLimit(): int
	{
		$value =
			$this->environment->isCloudPortal()
				? $this->getBaseLimitForCloud()
				: $this->getBaseLimitForBox()
		;

		$baasDocumentQuotaService = ServiceLocator::getInstance()->get(BaasSessionBoostService::class);

		if ($value && $baasDocumentQuotaService->isActual())
		{
			$value += $baasDocumentQuotaService->getQuota();
		}

		return $value ?? self::UNLIMITED_VALUE;
	}

	public function lock(): bool
	{
		$connection = Application::getConnection();

		return $connection->lock(self::LOCK_NAME, self::LOCK_LIMIT);
	}

	public function unlock(): void
	{
		$connection = Application::getConnection();

		$connection->unlock(self::LOCK_NAME);
	}

	/**
	 * Holds back the publications of this instance until {@see self::flushDeferredEvents()}.
	 *
	 * Off by default, so a caller that never asks publishes where it always did. It is asked for by the one
	 * that decides about the slots under the portal lock: the number of an event is counted inside the
	 * section, the sending of it has no business there
	 * ({@see \Bitrix\Disk\Document\Vibeoffice\EditSessionAdmission::admit()}).
	 */
	public function deferEvents(): void
	{
		$this->eventsDeferred = true;
	}

	public function flushDeferredEvents(): void
	{
		$this->eventsDeferred = false;
		$deferred = $this->deferredEvents;
		$this->deferredEvents = [];

		foreach ($deferred as $event)
		{
			self::emitEvent($event['type'], $event['availableCount']);
		}
	}

	public function isAllowedEdit(string $documentKey, int $userId): bool
	{
		if ($this->checkLimit())
		{
			return true;
		}

		if ($this->existsSession($documentKey, $userId))
		{
			return true;
		}

		return false;
	}

	public function isAllowedEditByObjectId(int $objectId, int $userId): bool
	{
		if ($this->checkLimit())
		{
			return true;
		}

		if ($this->isExistsSessionByObjectId($objectId, $userId))
		{
			return true;
		}

		return false;
	}

	/**
	 * Answers whether the pair already holds a slot, and keeps the row it found alive.
	 *
	 * A repeated entry is work resumed, not a new one: the row of the pair may be counting down
	 * the grace window left by a tab closed a moment ago ({@see self::expireUsageSoonByObjectForUser()}),
	 * and the user who opens the document again would otherwise edit against a row marked for removal.
	 */
	public function existsUsageForObject(int $objectId, int $userId): bool
	{
		if (!$this->isExistsSessionByObjectId($objectId, $userId))
		{
			return false;
		}

		$this->prolongUsageByObjectForUser($objectId, $userId);

		return true;
	}

	public function hasAvailableDocumentSessionSlot(): bool
	{
		return $this->checkLimit();
	}

	/**
	 * Answers whether a new session fits into the limit, counting the journal once.
	 *
	 * Expired VibeOffice rows are removed right before the count: a row left in place is counted as
	 * a taken slot, and the first attempt after a release would be refused while the hourly cleanup
	 * agent is still waiting for its turn. The event of that cleanup carries the number of the same
	 * count, so the journal is not counted again for it.
	 */
	public function checkDocumentSessionSlot(): DocumentSessionSlotCheck
	{
		$limit = $this->getLimit();
		if ($limit === self::UNLIMITED_VALUE)
		{
			return DocumentSessionSlotCheck::unlimited();
		}

		$deletedCount = $this->deleteExpiredVibeofficeRows();

		$countSessions = $this->countSessions();
		if ($this->isEnoughToDeletePendingUsages($limit, $countSessions))
		{
			$this->addJobToCleanPendingUsages();
		}

		if ($deletedCount > 0)
		{
			$this->publishEvent(
				self::DELETE_SESSION_EVENT,
				DocumentSessionSlotCheck::availableCount($limit, $countSessions),
			);
		}

		return DocumentSessionSlotCheck::counted($limit, $countSessions);
	}

	public function getAvailableDocumentSessionCount(): int
	{
		$limit = $this->getLimit();
		if ($limit >= self::UNLIMITED_VALUE)
		{
			return self::UNLIMITED_VALUE;
		}

		return DocumentSessionSlotCheck::availableCount($limit, $this->countSessions());
	}

	protected function isEnoughToDeletePendingUsages(int $limit, int $countSessions): bool
	{
		return $countSessions > ($limit / 2);
	}

	protected function addJobToCleanPendingUsages(): void
	{
		Application::getInstance()->addBackgroundJob(fn () => $this->deletePendingUsages());
	}

	public function registerUsage(string $documentKey, int $userId, ?DocumentService $service = null): void
	{
		$this->registerPendingUsage($documentKey, $userId, $service);
	}

	/**
	 * @param int|null $availableCountAfterSave the number the caller has already counted under its lock;
	 * null counts the journal anew for the event
	 * @param bool $keyIsFresh the key has just been generated and cannot be in the journal yet, so the
	 * count that asks about it is skipped
	 */
	public function registerPendingUsage(
		string $documentKey,
		int $userId,
		?DocumentService $service = null,
		?int $availableCountAfterSave = null,
		bool $keyIsFresh = false,
	): ?bool
	{
		if (!$keyIsFresh && $this->existsSession($documentKey, $userId))
		{
			return false;
		}

		$restrictionLog = new RestrictionLog();
		$restrictionLog
			->setUserId($userId)
			->setExternalHash($documentKey)
		;

		if ($service !== null)
		{
			$restrictionLog->setService($service->value);
		}

		$result = $restrictionLog->save();
		if (!$result->isSuccess())
		{
			return null;
		}

		$this->publishEvent(self::SAVE_SESSION_EVENT, $availableCountAfterSave);

		return true;
	}

	public function cancelPendingUsage(string $documentKey, int $userId): void
	{
		$entries = RestrictionLogTable::query()
			->setSelect(['ID'])
			->where('EXTERNAL_HASH', $documentKey)
			->where('USER_ID', $userId)
			->where('STATUS', RestrictionLogTable::STATUS_PENDING)
			->fetchCollection()
		;
		foreach ($entries as $entry)
		{
			$entry->delete();
		}

		if (!$entries->isEmpty())
		{
			self::emitEvent(self::DELETE_SESSION_EVENT);
		}
	}

	/**
	 * Deletes all restriction log entries for a document inside the caller's transaction.
	 *
	 * `availableCount` is diagnostics for the notification only, and reading the limit talks to
	 * an external service on box ({@see self::getBaseLimitForBox()}), so its failure is reported
	 * as an unknown count instead of an error: a temporarily unreachable limit source must not
	 * roll back the caller's cleanup and leave the slot taken.
	 */
	public function deleteByExternalHash(string $documentKey): Result
	{
		$result = new Result();

		try
		{
			$deletedCount = $this->deleteEntries($documentKey);
		}
		catch (Throwable $exception)
		{
			return $result->addError(new Error(
				$exception->getMessage(),
				'restriction_log_delete_failed',
			));
		}

		return $result->setData([
			'deletedCount' => $deletedCount,
			'availableCount' => $this->getAvailableDocumentSessionCountOrNull(),
		]);
	}

	/**
	 * Releases the slot of one user on one object inside the caller's transaction.
	 *
	 * The row is addressed by the pair, not by the key it was registered under: an object may be
	 * opened under several document keys ({@see self::bindPendingUsage()}), and the caller knows
	 * the object, not the key of the row. The keys are resolved through the sessions of this user
	 * on the object ({@see self::getDocumentKeysByObject()}), so the rows of co-editors stay untouched.
	 *
	 * `availableCount` follows {@see self::deleteByExternalHash()}: an unreachable limit source is
	 * reported as an unknown count instead of rolling back the cleanup.
	 */
	public function deleteUsageByObjectForUser(int $objectId, int $userId): Result
	{
		$result = new Result();

		try
		{
			$documentKeys = $this->getDocumentKeysByObject($objectId, $userId);
			$deletedCount =
				$documentKeys === []
					? 0
					: $this->deleteEntriesByDocumentKeysForUser($documentKeys, $userId)
			;
		}
		catch (Throwable $exception)
		{
			return $result->addError(new Error(
				$exception->getMessage(),
				'restriction_log_delete_failed',
			));
		}

		return $result->setData([
			'deletedCount' => $deletedCount,
			'availableCount' => $this->getAvailableDocumentSessionCountOrNull(),
		]);
	}

	/**
	 * Releases the slots every participant holds on one object inside the caller's transaction.
	 *
	 * This is what the removal of an object needs: the rows of the journal are addressed only
	 * through the sessions of the object ({@see self::getDocumentKeysOfObject()}), so once the
	 * session rows are gone nothing names them any more - neither the cleanup of the pair, nor
	 * prolonging, nor shortening reaches them - and the slots stay taken until the TTL of the rows
	 * runs out. Hence no filter by service either: an OnlyOffice row of the removed object is as
	 * unreachable afterwards as a VibeOffice one, and its TTL is the longer of the two.
	 *
	 * Unlike the resolution of one pair, the keys go from the sessions of the object to the journal,
	 * for the reason {@see self::getDocumentKeysOfObject()} gives.
	 *
	 * `availableCount` is counted only when a slot was freed - every deletion of a file comes here,
	 * and the count of the journal with the limit behind it is paid for nothing otherwise - and is
	 * null when nothing was deleted. When counted, it follows {@see self::deleteByExternalHash()}:
	 * an unreachable limit source is reported as an unknown count instead of rolling back the cleanup.
	 */
	public function deleteUsageByObject(int $objectId): Result
	{
		$result = new Result();

		try
		{
			$deletedCount = 0;
			foreach ($this->getDocumentKeysOfObject($objectId) as $userId => $documentKeys)
			{
				$deletedCount += $this->deleteEntriesByDocumentKeysForUser($documentKeys, $userId);
			}
		}
		catch (Throwable $exception)
		{
			return $result->addError(new Error(
				$exception->getMessage(),
				'restriction_log_delete_failed',
			));
		}

		return $result->setData([
			'deletedCount' => $deletedCount,
			'availableCount' => $deletedCount > 0 ? $this->getAvailableDocumentSessionCountOrNull() : null,
		]);
	}

	/**
	 * Shortens the life of the row of the pair to the grace window instead of deleting it.
	 *
	 * This is what a closed tab leaves behind: another tab of the same user may still be working,
	 * and its next signal ({@see self::prolongUsageByObjectForUser()} of P3) puts the row back to
	 * full life. The write goes through updateBatch on purpose - an ORM update would run
	 * {@see RestrictionLogTable::onBeforeUpdate()} and stamp UPDATE_TIME anew.
	 */
	public function expireUsageSoonByObjectForUser(int $objectId, int $userId): void
	{
		$documentKeys = $this->getDocumentKeysByObject($objectId, $userId);
		if ($documentKeys === [])
		{
			return;
		}

		RestrictionLogTable::updateBatch(
			[
				'UPDATE_TIME' => DateTime::createFromTimestamp(
					time() - (self::TTL_VIBEOFFICE - self::GRACE_VIBEOFFICE)
				),
			],
			[
				'@EXTERNAL_HASH' => $documentKeys,
				'=USER_ID' => $userId,
				'=STATUS' => RestrictionLogTable::STATUS_USED,
			],
		);
	}

	/**
	 * Gives the row of the pair its full life back.
	 *
	 * This is what a live tab reports: the work goes on, no matter which key the row was registered
	 * under and whether a neighbouring tab of the same user has just been closed - a signal that
	 * arrives inside the grace window cancels the shortening
	 * ({@see self::expireUsageSoonByObjectForUser()}). The write goes through updateBatch for the
	 * same reason as the shortening does: one statement over the keys of the object, without
	 * {@see RestrictionLogTable::onBeforeUpdate()} in between.
	 */
	public function prolongUsageByObjectForUser(int $objectId, int $userId): void
	{
		$documentKeys = $this->getDocumentKeysByObject($objectId, $userId);
		if ($documentKeys === [])
		{
			return;
		}

		RestrictionLogTable::updateBatch(
			[
				'UPDATE_TIME' => new DateTime(),
			],
			[
				'@EXTERNAL_HASH' => $documentKeys,
				'=USER_ID' => $userId,
				'=STATUS' => RestrictionLogTable::STATUS_USED,
			],
		);
	}

	/**
	 * Keeps the slot of a working pair, registering it anew when the row is already gone.
	 *
	 * Prolonging alone is not enough: a tab whose timers were frozen longer than
	 * {@see self::TTL_VIBEOFFICE} (a sleeping laptop, a lost connection) comes back to a row that
	 * {@see self::checkDocumentSessionSlot()} has already reclaimed, and an UPDATE over a row
	 * that no longer exists would leave the editing user out of the count until the next open.
	 *
	 * The new row is written with STATUS_USED right away - a reservation left at STATUS_PENDING is
	 * what {@see self::deletePendingUsages()} reclaims two minutes later. The limit is deliberately
	 * not re-checked: the user is already editing, and refusing here would take the slot away from
	 * work in progress. The count can exceed the limit when the slot was handed to someone else in
	 * the meantime, and the short TTL does not end that: the returned tab keeps signalling and keeps
	 * its row alive, so the excess lasts until one of the two documents is closed.
	 *
	 * The session is re-read inside the lock, right before the row is written: the caller answers
	 * from the model it read when the request came in, and the slot may have been returned since -
	 * the rights revocation finishes the sessions it terminates and only then releases their slots
	 * ({@see \Bitrix\Disk\Document\Vibeoffice\Service\VibeofficeSessionTerminationService}). Without
	 * that re-read a signal landing in between registers the row of a finished session anew, and the
	 * slot stays taken with nobody left to give it back before the short TTL.
	 */
	public function ensureUsageByObjectForUser(int $objectId, int $userId, string $documentKey, int $sessionId): void
	{
		if ($this->isExistsSessionByObjectId($objectId, $userId))
		{
			$this->prolongUsageByObjectForUser($objectId, $userId);

			return;
		}

		if (!$this->lock())
		{
			return;
		}

		try
		{
			if ($this->isExistsSessionByObjectId($objectId, $userId))
			{
				$this->prolongUsageByObjectForUser($objectId, $userId);

				return;
			}

			if (!$this->isSessionActive($sessionId))
			{
				return;
			}

			$restrictionLog = new RestrictionLog();
			$restrictionLog
				->setUserId($userId)
				->setExternalHash($documentKey)
				->setService(DocumentService::Vibeoffice->value)
				->setStatus(RestrictionLogTable::STATUS_USED)
			;

			if ($restrictionLog->save()->isSuccess())
			{
				self::emitEvent(self::SAVE_SESSION_EVENT);
			}
		}
		finally
		{
			$this->unlock();
		}
	}

	private function getAvailableDocumentSessionCountOrNull(): ?int
	{
		try
		{
			return $this->getAvailableDocumentSessionCount();
		}
		catch (Throwable)
		{
			return null;
		}
	}

	/**
	 * Publishes the restriction count calculated by a committed cleanup.
	 */
	public function notifyRestrictionLogChanged(int $availableCount): void
	{
		self::emitEvent(self::DELETE_SESSION_EVENT, $availableCount);
	}

	/**
	 * Binds a reservation made under a temporary key to the document key of the opened session
	 * and marks the slot as taken.
	 *
	 * The status is what keeps the slot alive: a row left in STATUS_PENDING is removed by the
	 * TTL_PENDING cleanup ({@see self::deletePendingUsages()}, {@see self::deleteOldOrPending()})
	 * a couple of minutes into the session. Services that have no per-document hook to promote the
	 * row later ({@see self::processHookData()} is the OnlyOffice one) rely on this call.
	 *
	 * When the session kept the reserved key (the usual first open) there is nothing to rename,
	 * only the status to promote.
	 */
	public function bindPendingUsage(string $reservationKey, string $documentKey, int $userId): void
	{
		if ($reservationKey === $documentKey)
		{
			$this->markPendingUsageAsUsed($documentKey, $userId);

			return;
		}

		if ($this->existsSession($documentKey, $userId))
		{
			$this->cancelPendingUsage($reservationKey, $userId);

			return;
		}

		RestrictionLogTable::updateBatch(
			[
				'EXTERNAL_HASH' => $documentKey,
				'STATUS' => RestrictionLogTable::STATUS_USED,
				'UPDATE_TIME' => new DateTime(),
			],
			[
				'=EXTERNAL_HASH' => $reservationKey,
				'=USER_ID' => $userId,
				'=STATUS' => RestrictionLogTable::STATUS_PENDING,
			],
		);
	}

	private function markPendingUsageAsUsed(string $documentKey, int $userId): void
	{
		RestrictionLogTable::updateBatch(
			[
				'STATUS' => RestrictionLogTable::STATUS_USED,
				'UPDATE_TIME' => new DateTime(),
			],
			[
				'=EXTERNAL_HASH' => $documentKey,
				'=USER_ID' => $userId,
				'=STATUS' => RestrictionLogTable::STATUS_PENDING,
			],
		);
	}

	public function processHookData(int $status, array $hookData): void
	{
		$documentKey = $hookData['key'] ?? null;
		if (!$documentKey)
		{
			return;
		}

		$usersInDocument = $hookData['users'] ?? [];
		$usersInDocument = array_map('\intval', $usersInDocument);

		$usersWhoFinished = [];
		$actions = $hookData['actions'] ?? [];
		foreach ($actions as $action)
		{
			$type = $action['type'] ?? null;
			$userId = (int)($action['userid'] ?? null);

			if (($type === Enum\UserAction::DISCONNECT) && !\in_array($userId, $usersInDocument, true))
			{
				$usersWhoFinished[] = $userId;
			}
		}

		if ($this->isDocumentClosed($status))
		{
			$this->deleteEntriesByExternalHash($documentKey);

			return;
		}

		$this->updateEntriesActivityByDocumentKey($documentKey);
		if ($status === Enum\Status::IS_BEING_EDITED)
		{
			$this->deleteUserEntriesByDocumentKey($usersWhoFinished, $documentKey);
		}
	}

	protected function isDocumentClosed(int $status): bool
	{
		return in_array($status, [
			Enum\Status::IS_READY_FOR_SAVE,
			Enum\Status::ERROR_WHILE_SAVING,
			Enum\Status::CLOSE_WITHOUT_CHANGES,
		], true);
	}

	protected function updateEntriesActivityByDocumentKey(string $documentKey): void
	{
		$filter = [
			'=EXTERNAL_HASH' => $documentKey,
		];

		RestrictionLogTable::updateBatch([
			'UPDATE_TIME' => new DateTime(),
			'STATUS' => RestrictionLogTable::STATUS_USED,
		], $filter);

	}

	protected function deleteUserEntriesByDocumentKey(array $userIds, string $documentKey): void
	{
		if (!$userIds)
		{
			return;
		}

		$this->deleteEntriesByExternalHash($documentKey, $userIds);
	}

	protected function deleteEntriesByExternalHash(string $documentKey, array $userIds = null): void
	{
		$this->deleteEntries($documentKey, $userIds);

		self::emitEvent(self::DELETE_SESSION_EVENT);
	}

	private function deleteEntries(string $documentKey, ?array $userIds = null): int
	{
		$connection = Application::getConnection();
		$tableName = RestrictionLogTable::getTableName();
		$sqlHelper = $connection->getSqlHelper();
		$documentKey = $sqlHelper->forSql($documentKey);

		$sql = "
			DELETE FROM {$tableName} WHERE EXTERNAL_HASH = '{$documentKey}' 
		";

		if ($userIds !== null)
		{
			$userIdsString = implode(',', $userIds);
			$sql .= " AND USER_ID IN ({$userIdsString})";
		}

		$connection->queryExecute($sql);

		return $connection->getAffectedRowsCount();
	}

	/**
	 * @param string[] $documentKeys
	 */
	private function deleteEntriesByDocumentKeysForUser(array $documentKeys, int $userId): int
	{
		$connection = Application::getConnection();
		$tableName = RestrictionLogTable::getTableName();
		$sqlHelper = $connection->getSqlHelper();
		$keyList = implode(',', array_map(
			static fn (string $documentKey): string => "'" . $sqlHelper->forSql($documentKey) . "'",
			$documentKeys,
		));

		$connection->queryExecute("
			DELETE FROM {$tableName}
			WHERE EXTERNAL_HASH IN ({$keyList}) AND USER_ID = {$userId}
		");

		return $connection->getAffectedRowsCount();
	}

	/**
	 * Sessions of one user are never shared with a co-editor: every participant gets a key of their
	 * own ({@see \Bitrix\Disk\Document\Models\DocumentSession::forkForUser()}), so the pair is resolved by the keys
	 * of this user alone.
	 *
	 * The log leads the resolution, not the sessions: it holds no more rows than the limit of the
	 * portal allows, while the session table keeps a row per opening for the whole life of the
	 * object and is never pruned. Walking it the other way round would read the whole history of
	 * the pair - and the callers ask on every keep-alive signal. Only the keys already logged for
	 * this user can name a row of theirs, so the sessions are asked about those keys alone.
	 *
	 * @return string[] the keys of the object the user holds a logged row under
	 */
	private function getDocumentKeysByObject(int $objectId, int $userId): array
	{
		$loggedKeys = [];
		$logRows = RestrictionLogTable::query()
			->setSelect(['EXTERNAL_HASH'])
			->where('USER_ID', $userId)
			->exec()
		;
		while ($logRow = $logRows->fetch())
		{
			$loggedKeys[$logRow['EXTERNAL_HASH']] = true;
		}

		if ($loggedKeys === [])
		{
			return [];
		}

		$documentKeys = [];
		// The log is bounded by the limit of the portal, yet a boost may raise it well past a
		// comfortable IN list - the same chunking the finalizer applies to its session ids.
		foreach (array_chunk(array_keys($loggedKeys), 300) as $chunk)
		{
			$rows = DocumentSessionTable::query()
				->setSelect(['EXTERNAL_HASH'])
				->where('OBJECT_ID', $objectId)
				->where('USER_ID', $userId)
				->whereIn('EXTERNAL_HASH', $chunk)
				->exec()
			;
			while ($row = $rows->fetch())
			{
				$documentKeys[$row['EXTERNAL_HASH']] = true;
			}
		}

		return array_keys($documentKeys);
	}

	/**
	 * The keys of the object every participant holds a logged row under.
	 *
	 * The resolution runs the other way round from {@see self::getDocumentKeysByObject()}: the sessions
	 * of the object lead, and the journal is asked about their keys alone. The pair is resolved on
	 * every keep-alive signal, where the whole history of openings would be read again and again;
	 * this one runs once, on the removal of the object, and every file of a cascade comes here -
	 * most of them never opened, or opened long ago with nothing left in the journal. The history of
	 * one object is found by the index on OBJECT_ID, and the journal by the index on its keys, so
	 * neither table is read whole.
	 *
	 * A key names the row of one user ({@see \Bitrix\Disk\Document\Models\DocumentSession::forkForUser()}),
	 * so a row logged under a key of the object by somebody without a session under it names nothing of
	 * the object and is left alone.
	 *
	 * @return array<int, string[]> the keys of the object every user holds a logged row under
	 */
	private function getDocumentKeysOfObject(int $objectId): array
	{
		$sessionKeys = [];
		$sessionRows = DocumentSessionTable::query()
			->setSelect(['EXTERNAL_HASH', 'USER_ID'])
			->setDistinct()
			->where('OBJECT_ID', $objectId)
			->exec()
		;
		while ($sessionRow = $sessionRows->fetch())
		{
			$sessionKeys[$sessionRow['EXTERNAL_HASH']][(int)$sessionRow['USER_ID']] = true;
		}

		if ($sessionKeys === [])
		{
			return [];
		}

		$documentKeys = [];
		// An object opened many times has a long history of keys - the same chunking the resolution
		// of one pair applies to its IN list.
		foreach (array_chunk(array_keys($sessionKeys), 300) as $chunk)
		{
			$logRows = RestrictionLogTable::query()
				->setSelect(['EXTERNAL_HASH', 'USER_ID'])
				->whereIn('EXTERNAL_HASH', $chunk)
				->exec()
			;
			while ($logRow = $logRows->fetch())
			{
				$userId = (int)$logRow['USER_ID'];
				if (isset($sessionKeys[$logRow['EXTERNAL_HASH']][$userId]))
				{
					$documentKeys[$userId][$logRow['EXTERNAL_HASH']] = true;
				}
			}
		}

		return array_map(
			static fn (array $keys): array => array_keys($keys),
			$documentKeys,
		);
	}

	protected function countSessions(): int
	{
		return RestrictionLogTable::query()
			->queryCountTotal();
	}

	protected function existsSession(string $documentKey, int $userId): bool
	{
		$countSession = RestrictionLogTable::query()
			->where('EXTERNAL_HASH', $documentKey)
			->where('USER_ID', $userId)
			->queryCountTotal();

		return $countSession > 0;
	}

	/**
	 * Tells a session still alive in the database from the model the caller has been holding.
	 *
	 * A finished session is not a lost one: it is read by the primary key, and a deleted row answers
	 * the same as a closed one - the slot of such a pair is nobody's to take back.
	 */
	private function isSessionActive(int $sessionId): bool
	{
		$row = DocumentSessionTable::query()
			->setSelect(['STATUS'])
			->where('ID', $sessionId)
			->setLimit(1)
			->fetch()
		;

		return $row !== false && (int)$row['STATUS'] === DocumentSessionTable::STATUS_ACTIVE;
	}

	private function isExistsSessionByObjectId(int $objectId, int $userId): bool
	{
		$referenceField = new ReferenceField(
			'SESSION',
			DocumentSessionTable::class,
			Join::on('this.EXTERNAL_HASH', 'ref.EXTERNAL_HASH')
		);
		$referenceField->configureJoinType(Join::TYPE_INNER);

		$query =
			(new Query(RestrictionLogTable::getEntity()))
				->setSelect(['ID'])
				->registerRuntimeField($referenceField)
				->where('USER_ID', $userId)
				->where('SESSION.OBJECT_ID', $objectId)
				->setLimit(1)
		;

		return (bool)$query->fetch();
	}

	public function deletePendingUsages(): void
	{
		$connection = Application::getConnection();
		$tableName = RestrictionLogTable::getTableName();
		$ttlTimeForPending = $connection->getSqlHelper()->convertToDbDateTime(
			DateTime::createFromTimestamp(time() - self::TTL_PENDING)
		);
		$statusPending = RestrictionLogTable::STATUS_PENDING;

		$connection->query( "
			DELETE FROM {$tableName}
			WHERE 
				UPDATE_TIME < {$ttlTimeForPending} AND STATUS = {$statusPending}
		");


		self::emitEvent(self::DELETE_SESSION_EVENT);
	}

	/**
	 * Reclaims the slots of VibeOffice rows whose short TTL has run out.
	 *
	 * The admission path does the same inside {@see self::checkDocumentSessionSlot()}.
	 */
	public function deleteExpiredVibeofficeUsages(): void
	{
		if ($this->deleteExpiredVibeofficeRows() <= 0)
		{
			return;
		}

		$availableCount = $this->getAvailableDocumentSessionCountOrNull();
		if ($availableCount === null)
		{
			return;
		}

		self::emitEvent(self::DELETE_SESSION_EVENT, $availableCount);
	}

	private function deleteExpiredVibeofficeRows(): int
	{
		$connection = Application::getConnection();
		$tableName = RestrictionLogTable::getTableName();
		$sqlHelper = $connection->getSqlHelper();
		$ttlTime = $sqlHelper->convertToDbDateTime(
			DateTime::createFromTimestamp(time() - self::TTL_VIBEOFFICE)
		);
		$vibeoffice = $sqlHelper->forSql(DocumentService::Vibeoffice->value);

		$connection->queryExecute("
			DELETE FROM {$tableName}
			WHERE UPDATE_TIME < {$ttlTime} AND SERVICE = '{$vibeoffice}'
		");

		return $connection->getAffectedRowsCount();
	}

	public static function deleteOldOrPendingAgent(): string
	{
		self::deleteOldOrPending();

		return self::class . '::deleteOldOrPendingAgent();';
	}

	public static function deleteOldOrPending(): void
	{
		$connection = Application::getConnection();
		$tableName = RestrictionLogTable::getTableName();
		$sqlHelper = $connection->getSqlHelper();
		$ttlTime = $sqlHelper->convertToDbDateTime(
			DateTime::createFromTimestamp(time() - self::TTL)
		);
		$ttlTimeForVibeoffice = $sqlHelper->convertToDbDateTime(
			DateTime::createFromTimestamp(time() - self::TTL_VIBEOFFICE)
		);
		$ttlTimeForPending = $sqlHelper->convertToDbDateTime(
			DateTime::createFromTimestamp(time() - self::TTL_PENDING)
		);
		$statusPending = RestrictionLogTable::STATUS_PENDING;
		$vibeoffice = $sqlHelper->forSql(DocumentService::Vibeoffice->value);

		// The four-hour branch asks about UPDATE_TIME alone, so it can be answered by
		// IX_DISK_O_RL_UPDATE: it is longer than the VibeOffice one, so a VibeOffice row old enough
		// to match it has already been taken by the branch of its own service.
		$connection->query("
			DELETE FROM {$tableName}
			WHERE
				UPDATE_TIME < {$ttlTime}
				OR (SERVICE = '{$vibeoffice}' AND UPDATE_TIME < {$ttlTimeForVibeoffice})
				OR (UPDATE_TIME < {$ttlTimeForPending} AND STATUS = {$statusPending})
		");

		self::emitEvent(self::DELETE_SESSION_EVENT);
	}

	private function publishEvent(string $type, ?int $availableDocumentSessionCount = null): void
	{
		if ($this->eventsDeferred)
		{
			$this->deferredEvents[] = [
				'type' => $type,
				'availableCount' => $availableDocumentSessionCount,
			];

			return;
		}

		self::emitEvent($type, $availableDocumentSessionCount);
	}

	private static function emitEvent(string $type, ?int $availableDocumentSessionCount = null): void
	{
		if ($availableDocumentSessionCount === null)
		{
			$availableDocumentSessionCount = (new self)->getAvailableDocumentSessionCount();
		}

		$event = new Event(Driver::INTERNAL_MODULE_ID, $type);
		$event->setParameter('availableDocumentSessionCount', $availableDocumentSessionCount);
		$event->send();
	}

	private function checkLimit(): bool
	{
		return $this->checkDocumentSessionSlot()->isSlotFree();
	}

	protected function shouldUseRestrictionForCloud(): bool
	{
		return $this->getLimit() !== self::UNLIMITED_VALUE;
	}

	protected function shouldUseRestrictionForBox(): bool
	{
		$cloudRegistrationData =
			ServiceLocator::getInstance()
				->get('disk.onlyofficeConfiguration')
				->getCloudRegistrationData()
		;

		return is_array($cloudRegistrationData);
	}

	protected function getBaseLimitForCloud(): ?int
	{
		return Bitrix24Manager::getFeatureVariable('disk_oo_edit_restriction');
	}

	protected function getBaseLimitForBox(): ?int
	{
		$cloudRegistrationData =
			ServiceLocator::getInstance()
				->get('disk.onlyofficeConfiguration')
				->getCloudRegistrationData()
		;

		if (!is_array($cloudRegistrationData))
		{
			return null;
		}

		$limitResult = (new LimitInfo($cloudRegistrationData['serverHost']))->getClientLimit();
		$error = $limitResult->getError();

		if (!$limitResult->isSuccess() && $error instanceof Error)
		{
			$code = $error->getCode();

			if (!is_int($code))
			{
				$code = 0;
			}

			throw new RuntimeException(
				message: $error->getMessage(),
				code: $code,
			);
		}

		return $limitResult->getData()['limit'] ?? null;
	}
}

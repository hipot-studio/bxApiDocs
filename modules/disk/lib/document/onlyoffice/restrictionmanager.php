<?php

namespace Bitrix\Disk\Document\OnlyOffice;

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
	public const TTL_PENDING = 2*60;
	public const SAVE_SESSION_EVENT = 'OnSaveSessionInRestrictionLog';
	public const DELETE_SESSION_EVENT = 'OnDeleteSessionsFromRestrictionLog';

	protected const LOCK_LIMIT = 15;

	protected Config\Configuration $config;
	protected Environment $environment;

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

	public function getLimit(): int
	{
		$value =
			$this->environment->isCloudPortal()
				? $this->getBaseLimitForCloud()
				: $this->getBaseLimitForBox()
		;

		$baasDocumentQuotaService = new BaasSessionBoostService();

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

	public function existsUsageForObject(int $objectId, int $userId): bool
	{
		return $this->isExistsSessionByObjectId($objectId, $userId);
	}

	public function hasAvailableDocumentSessionSlot(): bool
	{
		return $this->checkLimit();
	}

	public function getAvailableDocumentSessionCount(): int
	{
		$limit = $this->getLimit();
		if ($limit >= self::UNLIMITED_VALUE)
		{
			return self::UNLIMITED_VALUE;
		}

		return $limit - $this->countSessions();
	}

	protected function isEnoughToDeletePendingUsages(int $limit, int $countSessions): bool
	{
		return $countSessions > ($limit / 2);
	}

	protected function addJobToCleanPendingUsages(): void
	{
		Application::getInstance()->addBackgroundJob(fn () => $this->deletePendingUsages());
	}

	public function registerUsage(string $documentKey, int $userId): void
	{
		$this->registerPendingUsage($documentKey, $userId);
	}

	public function registerPendingUsage(string $documentKey, int $userId): ?bool
	{
		if ($this->existsSession($documentKey, $userId))
		{
			return false;
		}

		$restrictionLog = new RestrictionLog();
		$restrictionLog
			->setUserId($userId)
			->setExternalHash($documentKey)
		;

		$result = $restrictionLog->save();
		if (!$result->isSuccess())
		{
			return null;
		}

		self::emitEvent(self::SAVE_SESSION_EVENT);

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

	public static function deleteOldOrPendingAgent(): string
	{
		self::deleteOldOrPending();

		return self::class . '::deleteOldOrPendingAgent();';
	}

	public static function deleteOldOrPending(): void
	{
		$connection = Application::getConnection();
		$tableName = RestrictionLogTable::getTableName();
		$ttlTime = $connection->getSqlHelper()->convertToDbDateTime(
			DateTime::createFromTimestamp(time() - self::TTL)
		);
		$ttlTimeForPending = $connection->getSqlHelper()->convertToDbDateTime(
			DateTime::createFromTimestamp(time() - self::TTL_PENDING)
		);
		$statusPending = RestrictionLogTable::STATUS_PENDING;

		$connection->query("
			DELETE FROM {$tableName}
			WHERE 
				UPDATE_TIME < {$ttlTime} OR (UPDATE_TIME < {$ttlTimeForPending} AND STATUS = {$statusPending})
		");

		self::emitEvent(self::DELETE_SESSION_EVENT);
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
		$limit = $this->getLimit();

		if ($limit === self::UNLIMITED_VALUE)
		{
			return true;
		}

		$countSessions = $this->countSessions();
		if ($this->isEnoughToDeletePendingUsages($limit, $countSessions))
		{
			$this->addJobToCleanPendingUsages();
		}

		return $limit > $countSessions;
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

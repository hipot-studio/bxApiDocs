<?php

namespace Bitrix\Disk\Document\OnlyOffice;

use Bitrix\Disk\Document\Models\RestrictionLog;
use Bitrix\Disk\Document\Models\RestrictionLogTable;
use Bitrix\Disk\Driver;
use Bitrix\Disk\Integration\Baas\BaasSessionBoostService;
use Bitrix\Disk\Integration\Bitrix24Manager;
use Bitrix\Main\Application;
use Bitrix\Main\Config;
use Bitrix\Main\Event;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Disk\Document\Models\DocumentSessionTable;
use CBitrix24;

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

	public function __construct()
	{
		$this->config = Config\Configuration::getInstance('disk');
	}

	public function shouldUseRestriction(): bool
	{
		if (!ModuleManager::isModuleInstalled('bitrix24'))
		{
			return false;
		}

		return $this->getLimit() !== self::UNLIMITED_VALUE;
	}

	public function getLimit(): int
	{
		$value = Bitrix24Manager::getFeatureVariable('disk_oo_edit_restriction');

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
		if ($this->existsSession($documentKey, $userId))
		{
			return;
		}

		$restrictionLog = new RestrictionLog();
		$restrictionLog
			->setUserId($userId)
			->setExternalHash($documentKey);

		$restrictionLog->save();

		self::emitEvent(self::SAVE_SESSION_EVENT);
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

		self::emitEvent(self::DELETE_SESSION_EVENT);
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
				->setSelect(['ID', 'OBJECT_ID' => 'SESSION.OBJECT_ID'])
				->registerRuntimeField($referenceField)
				->where('USER_ID', $userId)
		;

		$objectIds = array_map(static fn(array $item) => (int)$item['OBJECT_ID'], $query->fetchAll());

		return in_array($objectId, $objectIds, true);
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
	 * Determinate is current portal tariff extendable (max number of sessions).
	 *
	 * @return bool
	 */
	public function isCurrentTariffExtendable(): bool
	{
		$licenseType = CBitrix24::getLicenseType();

		if (!is_string($licenseType))
		{
			return true;
		}

		$extendableTariffs = $this->config->get('extendableTariffs');

		if (!is_array($extendableTariffs))
		{
			return true;
		}

		return in_array($licenseType, $extendableTariffs, true);
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

	private static function emitEvent(string $type): void
	{
		$event = new Event(Driver::INTERNAL_MODULE_ID, $type);
		$event->setParameter('availableDocumentSessionCount', (new self)->getAvailableDocumentSessionCount());
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
}
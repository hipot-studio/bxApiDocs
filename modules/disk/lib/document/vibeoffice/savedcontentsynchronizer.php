<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Vibeoffice\Models\PlatformSessionTable;
use Bitrix\Disk\Document\Vibeoffice\Models\SavedSnapshotTable;
use Bitrix\Disk\Document\Vibeoffice\Clients\ProtocolClientFactory;
use Bitrix\Disk\Document\Vibeoffice\Clients\ProtocolClientInterface;
use Bitrix\Disk\Document\Vibeoffice\Webhook\DeliveryTable;
use Bitrix\Disk\Document\Vibeoffice\Webhook\WebhookProcessor;
use Bitrix\Disk\File;
use Bitrix\Disk\Internals\FileTable;
use Bitrix\Disk\Internals\VersionTable;
use Bitrix\Main\Application;
use Bitrix\Main\Data\ConnectionPool;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Closure;

final class SavedContentSynchronizer
{
	public const ERROR_TIMEOUT = 'vibeoffice_sync_timeout';
	public const ERROR_UNKNOWN_OUTCOME = 'vibeoffice_sync_unknown_outcome';
	public const SYNC_LOCK_PREFIX = 'disk_vo_sync_';
	public const SYNC_LOCK_CONNECTION_NAME = 'disk_vo_sync';

	private const QUEUED_BUDGET_SEC = 6.0;
	private const RECENT_CLOSED_BUDGET_SEC = 3.0;
	private const POLL_INTERVAL_MS = 300;
	private const POLL_INTERVAL_GROWTH = 2;
	private const POLL_INTERVAL_MAX_FACTOR = 4;

	private const CONFLICT_STATUS = 409;
	private const RESULT_NOT_MODIFIED = 'not_modified';
	/** These replies still require delivery confirmation while the local session is active. */
	private const NOTHING_TO_SAVE_CODES = ['not_active', 'no_editor_session'];

	/** Sync locks this process holds on the dedicated connection, by lock name. */
	private static array $heldSyncLocks = [];

	private ProtocolClientInterface $protocolClient;
	private readonly Closure $sleeper;

	public function __construct(
		?ProtocolClientInterface $protocolClient = null,
		private readonly float $queuedBudgetSec = self::QUEUED_BUDGET_SEC,
		private readonly float $recentClosedBudgetSec = self::RECENT_CLOSED_BUDGET_SEC,
		private readonly int $pollIntervalMs = self::POLL_INTERVAL_MS,
		?Closure $sleeper = null,
	)
	{
		$this->protocolClient = $protocolClient ?? ProtocolClientFactory::create();
		$this->sleeper = $sleeper ?? static function (int $microseconds): void {
			usleep($microseconds);
		};
	}

	public static function getSyncLockName(int $realObjectId): string
	{
		return self::SYNC_LOCK_PREFIX . $realObjectId;
	}

	/**
	 * Persists an edit session before a live view session is created.
	 *
	 * The editor can be closed before the saved webhook finishes applying the new version, so this
	 * method is called only for live views. Each key needs its own applied delivery, because another
	 * editor can advance the shared file version. A just-closed session gets a shorter wait budget.
	 *
	 * Only one request of a document waits: the one that takes the sync lock. The other viewers
	 * return the current version at once and get the newer one by the contentUpdated pull command.
	 * A supplied opening budget bounds both the save requests and all subsequent polling.
	 *
	 * @return Result data: globalContentVersion, savingInProgress
	 */
	public function synchronize(File $file, ?OpeningBudget $budget = null): Result
	{
		if ($budget !== null && ($budget->remainingMs() <= 0
			|| DocumentStateRepository::hasOpenTransaction() || DocumentStateRepository::holdsAnyLock()))
		{
			return (new Result())->addError($this->createTimeoutError());
		}
		$fileId = (int)$file->getRealObjectId();
		$result = new Result();
		$openedContentBaselines = [];
		$sessionsByKey = $this->selectSessionsByKey($fileId, $result, $openedContentBaselines);
		if ($sessionsByKey === [])
		{
			return $result->isSuccess() ? $this->currentVersionResult($fileId, false) : $result;
		}

		$syncLockName = $this->tryAcquireSyncLock($fileId);
		if ($syncLockName === null)
		{
			return $result->isSuccess() ? $this->currentVersionResult($fileId, true) : $result;
		}

		try
		{
			$failure = $this->waitAsInitiator($fileId, $sessionsByKey, $result, $openedContentBaselines, $budget);
		}
		finally
		{
			self::releaseSyncLock($syncLockName);
		}
		if ($failure !== null)
		{
			return $failure;
		}

		return $result->isSuccess() ? $this->currentVersionResult($fileId, false) : $result;
	}

	/**
	 * The recency window lives in SQL, so neither the rows nor their CONTEXT grow with the history.
	 *
	 * @return array<string, DocumentSession> the session to synchronize of each document key
	 */
	private function selectSessionsByKey(int $fileId, Result $result, array &$openedContentBaselines): array
	{
		$sessions = iterator_to_array((new SavedEditSessionSelector())->iterate($fileId), false);

		$now = (new DateTime())->getTimestamp();
		$candidateKeys = [];
		foreach ($sessions as $session)
		{
			$key = $this->getRefusalCandidateKey($session, $fileId, $now);
			if ($key !== null)
			{
				$candidateKeys[$session->getId()] = $key;
			}
		}
		$registryResult = (new SessionRegistry())->getOpenRowsBySessionIds(array_keys($candidateKeys));
		$rowsBySessionId = $registryResult->isSuccess()
			? $registryResult->getData()['rowsBySessionId'] : [];

		$sessionsByKey = [];
		foreach ($sessions as $session)
		{
			$id = (int)$session->getId();
			if (isset($candidateKeys[$id]) && $this->hasOnlyRefusedRows($rowsBySessionId[$id] ?? [], $id, $candidateKeys[$id]))
			{
				continue;
			}

			$docKey = HostDocumentKey::resolve($session);
			if ($docKey === '')
			{
				$result->addError(new Error('The document key is unavailable.', 'vibeoffice_sync_key_missing'));

				continue;
			}

			if (!isset($sessionsByKey[$docKey]) || (!$sessionsByKey[$docKey]->isActive() && $session->isActive()))
			{
				$sessionsByKey[$docKey] = $session;
			}
			if ($session->isActive())
			{
				$baseline = DocumentSessionManager::extractVoMetadata($session)[
					DocumentSessionManager::CONTEXT_VO_OPENED_CONTENT_VERSION_KEY
				] ?? null;
				if (!is_int($baseline) || $baseline < 0)
				{
					$openedContentBaselines[$docKey] = null;
				}
				elseif (!array_key_exists($docKey, $openedContentBaselines)
					|| $openedContentBaselines[$docKey] !== null)
				{
					// Carrier IDs do not order platform opens of sessions sharing a key.
					$openedContentBaselines[$docKey] = max($baseline, $openedContentBaselines[$docKey] ?? 0);
				}
			}
		}

		return $sessionsByKey;
	}

	/**
	 * All keys share one deadline; a just-closed session waits no longer than its own budget inside it.
	 *
	 * @param array<string, DocumentSession> $sessionsByKey
	 * @return Result|null a failure that ends the synchronization at once
	 */
	private function waitAsInitiator(
		int $fileId,
		array $sessionsByKey,
		Result $result,
		array $openedContentBaselines,
		?OpeningBudget $budget,
	): ?Result
	{
		$deadline = $budget?->deadlineForOpen() ?? hrtime(true) / 1e9 + $this->queuedBudgetSec;
		foreach ($sessionsByKey as $docKey => $session)
		{
			if ($budget !== null && $budget->remainingMs() <= 0)
			{
				return $result->addError($this->createTimeoutError());
			}
			$metadata = DocumentSessionManager::extractVoMetadata($session);
			$contentBaseline = $metadata[DocumentSessionManager::CONTEXT_VO_CONTENT_VERSION_KEY] ?? null;
			$contentBaseline = is_int($contentBaseline) && $contentBaseline >= 0 ? $contentBaseline : null;
			$savedVersionBaseline = $metadata[DocumentSessionManager::CONTEXT_VO_SAVED_VERSION_ID_KEY] ?? null;
			$savedVersionBaseline = is_int($savedVersionBaseline) && $savedVersionBaseline >= 0
				? $savedVersionBaseline : null;
			if (!$session->isActive())
			{
				$closedDeadline = min(hrtime(true) / 1e9 + $this->recentClosedBudgetSec, $deadline);
				if (!$this->waitForVersion($fileId, $contentBaseline, $savedVersionBaseline, $docKey, $closedDeadline))
				{
					$result->addError($this->createTimeoutError());
				}

				continue;
			}

			// Each serial request starts after deliveries that arrived while earlier keys were waiting.
			$savedVersionBaseline = DocumentSessionManager::getLatestSavedVersionId($fileId);
			$currentFile = File::loadById($fileId);
			if ($currentFile === null)
			{
				return (new Result())->addError(new Error('The document is unavailable.', 'vibeoffice_sync_file_missing'));
			}
			// Old sessions stay in ID mode: their open-time content baseline cannot be reconstructed.
			if ($contentBaseline !== null)
			{
				$contentBaseline = (int)$currentFile->getGlobalContentVersion();
			}
			$forcesaveResult = $budget === null
				? $this->protocolClient->forcesave($docKey, null)
				: $this->protocolClient->forcesaveWithinDeadline($docKey, null, $deadline);
			if ($this->isVerifiedSaveRefusal($forcesaveResult, 404, ['not_found']))
			{
				continue;
			}
			if (!$forcesaveResult->isSuccess())
			{
				if ($this->isVerifiedSaveRefusal($forcesaveResult, self::CONFLICT_STATUS, self::NOTHING_TO_SAVE_CODES))
				{
					if (!$this->waitForVersion(
						$fileId, $openedContentBaselines[$docKey] ?? null, null, $docKey,
						$deadline, terminalOnly: true,
					))
					{
						$result->addError($this->createTimeoutError());
					}
				}
				else
				{
					$result->addErrors($forcesaveResult->getErrors());
				}

				continue;
			}
			if (!$this->isKnownSaveOutcome($forcesaveResult))
			{
				if ($forcesaveResult->isSuccess())
				{
					$result->addError(new Error('Unknown save outcome.', self::ERROR_UNKNOWN_OUTCOME));
				}
				else
				{
					$result->addErrors($forcesaveResult->getErrors());
				}

				continue;
			}
			// A settled not_modified reply certifies that this key has no save awaiting application.
			// Older platforms omit settled; they still need a newer applied receipt.
			if ($this->isSettledNotModified($forcesaveResult))
			{
				continue;
			}
			if (!$this->waitForVersion(
				$fileId, $contentBaseline, $savedVersionBaseline, $docKey, $deadline,
			))
			{
				$result->addError($this->createTimeoutError());
			}
		}

		return null;
	}

	private function currentVersionResult(int $fileId, bool $savingInProgress): Result
	{
		$currentFile = File::loadById($fileId);
		if ($currentFile === null)
		{
			return (new Result())->addError(new Error('The document is unavailable.', 'vibeoffice_sync_file_missing'));
		}

		return (new Result())->setData([
			'globalContentVersion' => (int)$currentFile->getGlobalContentVersion(),
			'savingInProgress' => $savingInProgress,
		]);
	}

	/**
	 * Returns the taken lock name, or null for a viewer. A process under a document lock is a viewer:
	 * the save confirmation is applied under that very lock and would never reach the wait.
	 */
	private function tryAcquireSyncLock(int $realObjectId): ?string
	{
		if (DocumentStateRepository::holdsAnyLock() || self::$heldSyncLocks !== [])
		{
			return null;
		}

		$lockName = self::getSyncLockName($realObjectId);
		if (!self::getSyncLockConnection()->lock($lockName, 0))
		{
			return null;
		}
		self::$heldSyncLocks[$lockName] = true;

		return $lockName;
	}

	private static function releaseSyncLock(string $lockName): void
	{
		unset(self::$heldSyncLocks[$lockName]);
		self::getSyncLockConnection()->unlock($lockName);
	}

	/**
	 * MySQL 5.6 keeps one GET_LOCK per connection and a new one drops the previous, so the sync lock
	 * lives on its own connection and never touches the locks of the main one.
	 */
	private static function getSyncLockConnection(): Connection
	{
		$pool = Application::getInstance()->getConnectionPool();

		return $pool->getConnection(self::SYNC_LOCK_CONNECTION_NAME)
			?? $pool->cloneConnection(ConnectionPool::DEFAULT_CONNECTION_NAME, self::SYNC_LOCK_CONNECTION_NAME);
	}

	private function getRefusalCandidateKey(DocumentSession $session, int $fileId, int $now): ?string
	{
		try
		{
			$age = $now - $session->getCreateTime()->getTimestamp();
			if (
				$session->getId() <= 0 || $age < 0
				|| $age > SavedEditSessionSelector::RECENT_NON_ACTIVE_SESSION_MAX_AGE_SEC
				|| $session->getObjectId() !== $fileId || $session->getVersionId() !== null
				|| $session->getType() !== DocumentSession::TYPE_EDIT
				|| $session->getService() !== DocumentService::Vibeoffice
			)
			{
				return null;
			}
			$raw = $session->getContextRaw();
			if (!is_string($raw) || !(json_decode($raw) instanceof \stdClass))
			{
				return null;
			}
			$context = json_decode($raw, true);
			$vo = $context['vo'] ?? null;
			$link = $context['externalLinkId'] ?? null;
			if (
				!is_array($vo) || !is_int($context['objectId'] ?? null) || $context['objectId'] !== $fileId
				|| ($link !== null && (!is_int($link) || $link < 0))
				|| ($link ?? 0) !== ($session->getExternalLinkId() ?? 0)
				|| !is_string($vo['host_doc_key'] ?? null) || $vo['host_doc_key'] === ''
				|| $vo['host_doc_key'] !== HostDocumentKey::resolve($session)
				|| !is_int($vo['contentVersion'] ?? null) || $vo['contentVersion'] < 0
				|| !is_int($vo['savedVersionId'] ?? null) || $vo['savedVersionId'] < 0
				|| !in_array($vo['session_id'] ?? null, [null, ''], true)
				|| !in_array($vo['editor_key'] ?? null, [null, ''], true)
				|| ($vo['rev'] ?? null) !== null
				|| OpenAttemptState::protectsCarrier($vo)
			)
			{
				return null;
			}

			return $vo['host_doc_key'];
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	private function hasOnlyRefusedRows(array $rows, int $sessionId, string $hostDocKey): bool
	{
		if (!$rows)
		{
			return false;
		}
		foreach ($rows as $row)
		{
			if (
				!is_array($row) || (int)($row['DOCUMENT_SESSION_ID'] ?? 0) !== $sessionId
				|| ($row['HOST_DOC_KEY'] ?? null) !== $hostDocKey
				|| ($row['STATUS'] ?? null) !== PlatformSessionTable::STATUS_REFUSED
				|| !is_string($row['OPEN_TOKEN'] ?? null)
				|| preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di', $row['OPEN_TOKEN']) !== 1
				|| !in_array($row['API_SESSION_ID'] ?? null, [null, ''], true)
				|| !in_array($row['EDITOR_KEY'] ?? null, [null, ''], true)
				|| ($row['REV'] ?? null) !== null
			)
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * A platform reply describes its state, not whether the portal has applied the saved content.
	 */
	private function isKnownSaveOutcome(Result $forcesaveResult): bool
	{
		return $forcesaveResult->isSuccess()
			&& in_array($forcesaveResult->getData()['result'] ?? null, ['queued', self::RESULT_NOT_MODIFIED], true);
	}

	private function isVerifiedSaveRefusal(Result $result, int $httpStatus, array $platformCodes): bool
	{
		if ($result->isSuccess() || count($result->getErrors()) !== 1)
		{
			return false;
		}
		$data = $result->getErrors()[0]->getCustomData();

		return is_array($data) && ($data['responseVerified'] ?? null) === true
			&& ($data['httpStatus'] ?? null) === $httpStatus
			&& in_array($data['platformCode'] ?? null, $platformCodes, true);
	}

	private function isSettledNotModified(Result $forcesaveResult): bool
	{
		if (!$forcesaveResult->isSuccess())
		{
			return false;
		}

		$data = $forcesaveResult->getData();

		return ($data['result'] ?? null) === self::RESULT_NOT_MODIFIED && ($data['settled'] ?? null) === true;
	}

	private function createTimeoutError(): Error
	{
		return new Error('Saved content synchronization timed out.', self::ERROR_TIMEOUT);
	}

	private function hasAppliedTerminalVersion(int $fileId, int $openedContentBaseline, string $docKey): bool
	{
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			$snapshot = SavedSnapshotTable::query()->setSelect(['*'])->where('HOST_DOC_KEY', $docKey)
				->setOrder(['SNAPSHOT_SEQ' => 'DESC'])->setLimit(1)->fetch()
			;
			if (!$snapshot || $snapshot['DELIVERY_ID'] === null || $snapshot['RESULT_CONTENT_VERSION'] === null
				|| (int)$snapshot['RESULT_OBJECT_ID'] !== $fileId
				|| (int)$snapshot['RESULT_CONTENT_VERSION'] <= $openedContentBaseline)
			{
				return false;
			}
			$key = Query::filter()->logic('or')
				->where('HOST_DOC_KEY', $docKey)
				->where(Query::filter()->whereNull('HOST_DOC_KEY')->where('EXTERNAL_HASH', $docKey))
			;
			// An unsequenced obligation cannot be ordered against this generation's final snapshot.
			if (DeliveryTable::query()->setSelect(['ID'])->where($key)
				->where('EVENT', WebhookProcessor::EVENT_SAVED)->whereNull('SNAPSHOT_SEQ')->setLimit(1)->fetch())
			{
				return false;
			}
			$delivery = DeliveryTable::query()->setSelect(['*'])->where($key)
				->where('EVENT', WebhookProcessor::EVENT_SAVED)
				->setOrder(['SNAPSHOT_SEQ' => 'DESC', 'DELIVERY_ID' => 'DESC'])->setLimit(1)->fetch()
			;
			if (!$delivery || !in_array($delivery['STATUS'], [DeliveryTable::STATUS_APPLIED, DeliveryTable::STATUS_DONE], true)
				|| $delivery['OUTCOME'] !== DeliveryTable::OUTCOME_APPLIED
				|| (int)$delivery['SNAPSHOT_SEQ'] !== (int)$snapshot['SNAPSHOT_SEQ']
				|| (int)$delivery['RESULT_OBJECT_ID'] !== $fileId
				|| $delivery['RESULT_CONTENT_VERSION'] === null
				|| (int)$delivery['RESULT_CONTENT_VERSION'] !== (int)$snapshot['RESULT_CONTENT_VERSION']
				|| (int)$delivery['RESULT_VERSION_ID'] <= 0
				|| (int)$delivery['RESULT_VERSION_ID'] !== (int)$snapshot['RESULT_VERSION_ID'])
			{
				return false;
			}
			$owner = (int)$delivery['DELIVERY_ID'] === (int)$snapshot['DELIVERY_ID'];
			if (!(($owner && in_array($snapshot['SOURCE'], ['save', 'forgotten'], true))
				|| (!$owner && $snapshot['SOURCE'] === 'forcesave')))
			{
				return false;
			}
			$liveFile = FileTable::query()->setSelect(['GLOBAL_CONTENT_VERSION'])->where('ID', $fileId)->fetch();

			return $liveFile && $liveFile['GLOBAL_CONTENT_VERSION'] !== null
				&& (int)$liveFile['GLOBAL_CONTENT_VERSION'] >= (int)$snapshot['RESULT_CONTENT_VERSION']
				&& (bool)VersionTable::query()->setSelect(['ID'])
					->where('ID', (int)$delivery['RESULT_VERSION_ID'])->where('OBJECT_ID', $fileId)->fetch()
			;
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}

	/** Freshness belongs to the receipt: a joined Version row can change after it was applied. */
	private function hasAppliedVersion(
		int $fileId,
		?int $contentBaseline,
		?int $savedVersionBaseline,
		string $docKey,
	): bool
	{
		if ($contentBaseline === null && $savedVersionBaseline === null)
		{
			return false;
		}
		$liveFile = FileTable::query()->setSelect(['GLOBAL_CONTENT_VERSION'])->where('ID', $fileId)->fetch();
		$keyFilters = [
			Query::filter()->where('HOST_DOC_KEY', $docKey),
			Query::filter()->whereNull('HOST_DOC_KEY')->where('EXTERNAL_HASH', $docKey),
		];
		// Key and proof modes use separate lookups to preserve their index paths.
		foreach ($keyFilters as $keyFilter)
		{
			$query = DeliveryTable::query()
				->setSelect(['ID'])
				->registerRuntimeField(new Reference(
					'SAVED_VERSION',
					VersionTable::class,
					['=this.RESULT_VERSION_ID' => 'ref.ID'],
					['join_type' => 'INNER'],
				))
				->where($keyFilter)
				->where('EVENT', WebhookProcessor::EVENT_SAVED)
				->whereIn('STATUS', [DeliveryTable::STATUS_APPLIED, DeliveryTable::STATUS_DONE])
				->where('OUTCOME', DeliveryTable::OUTCOME_APPLIED)
				->where('RESULT_OBJECT_ID', $fileId)
				->where('SAVED_VERSION.OBJECT_ID', $fileId)
				->setLimit(1)
			;
			if ($liveFile && $liveFile['GLOBAL_CONTENT_VERSION'] !== null)
			{
				$contentQuery = clone $query;
				$contentQuery
					->whereNotNull('RESULT_CONTENT_VERSION')
					->where('RESULT_CONTENT_VERSION', '<=', (int)$liveFile['GLOBAL_CONTENT_VERSION'])
				;
				if ($contentBaseline !== null)
				{
					$contentQuery->where('RESULT_CONTENT_VERSION', '>', $contentBaseline);
				}
				else
				{
					$contentQuery->where('RESULT_VERSION_ID', '>', $savedVersionBaseline);
				}
				if ($contentQuery->fetch())
				{
					return true;
				}
			}
			if ($savedVersionBaseline !== null && $query
				->whereNull('RESULT_CONTENT_VERSION')
				->where('RESULT_VERSION_ID', '>', $savedVersionBaseline)
				->fetch())
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Closed sessions without either pinned baseline cannot confirm delivery and return immediately.
	 *
	 * The interval between checks grows up to a cap: a save that lands quickly - the common case - is
	 * still noticed within the first interval, while a wait that runs on costs a fraction of the reads
	 * a fixed step would. The last sleep never outlives the budget.
	 */
	private function waitForVersion(
		int $fileId,
		?int $contentBaseline,
		?int $savedVersionBaseline,
		string $docKey,
		float $deadline,
		bool $terminalOnly = false,
	): bool
	{
		if ($contentBaseline === null && $savedVersionBaseline === null)
		{
			return false;
		}

		$intervalUsec = max(1, $this->pollIntervalMs * 1000);
		$maxIntervalUsec = max(1, $this->pollIntervalMs * self::POLL_INTERVAL_MAX_FACTOR * 1000);

		while (true)
		{
			if ($terminalOnly
				? $this->hasAppliedTerminalVersion($fileId, $contentBaseline, $docKey)
				: $this->hasAppliedVersion($fileId, $contentBaseline, $savedVersionBaseline, $docKey))
			{
				return true;
			}

			$remainingUsec = (int)floor(($deadline - hrtime(true) / 1e9) * 1000000);
			if ($remainingUsec <= 0)
			{
				return false;
			}

			($this->sleeper)(min($intervalUsec, $remainingUsec));
			$intervalUsec = min($intervalUsec * self::POLL_INTERVAL_GROWTH, $maxIntervalUsec);
		}
	}
}

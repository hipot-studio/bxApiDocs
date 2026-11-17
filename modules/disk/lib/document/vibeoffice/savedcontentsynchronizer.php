<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Vibeoffice\Clients\ProtocolClientFactory;
use Bitrix\Disk\Document\Vibeoffice\Clients\ProtocolClientInterface;
use Bitrix\Disk\Document\Vibeoffice\Webhook\DeliveryTable;
use Bitrix\Disk\Document\Vibeoffice\Webhook\WebhookProcessor;
use Bitrix\Disk\File;
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
	private const RECENT_NON_ACTIVE_SESSION_MAX_AGE_SEC = 60;

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
	 *
	 * @return Result data: globalContentVersion, savingInProgress
	 */
	public function synchronize(File $file): Result
	{
		$fileId = (int)$file->getRealObjectId();
		$result = new Result();
		$sessionsByKey = $this->selectSessionsByKey($fileId, $result);
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
			$failure = $this->waitAsInitiator($fileId, $sessionsByKey, $result);
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
	private function selectSessionsByKey(int $fileId, Result $result): array
	{
		$sessions = DocumentSession::getModelList([
			'select' => ['ID', 'EXTERNAL_HASH', 'STATUS', 'CONTEXT', 'CREATE_TIME'],
			'filter' => [
				'=OBJECT_ID' => $fileId,
				'=VERSION_ID' => null,
				'=TYPE' => DocumentSession::TYPE_EDIT,
				'=SERVICE' => DocumentService::Vibeoffice->value,
				[
					'LOGIC' => 'OR',
					['=STATUS' => DocumentSession::STATUS_ACTIVE],
					[
						'=STATUS' => DocumentSession::STATUS_NON_ACTIVE,
						'>=CREATE_TIME' => DateTime::createFromTimestamp(
							time() - self::RECENT_NON_ACTIVE_SESSION_MAX_AGE_SEC,
						),
					],
				],
			],
			'order' => ['ID' => 'DESC'],
		]);

		$sessionsByKey = [];
		foreach ($sessions as $session)
		{
			if (!$session->isActive() && !$this->isRecentNonActiveSession($session))
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
		}

		return $sessionsByKey;
	}

	/**
	 * All keys share one deadline; a just-closed session waits no longer than its own budget inside it.
	 *
	 * @param array<string, DocumentSession> $sessionsByKey
	 * @return Result|null a failure that ends the synchronization at once
	 */
	private function waitAsInitiator(int $fileId, array $sessionsByKey, Result $result): ?Result
	{
		$deadline = microtime(true) + $this->queuedBudgetSec;
		foreach ($sessionsByKey as $docKey => $session)
		{
			if (!$session->isActive())
			{
				$metadata = DocumentSessionManager::extractVoMetadata($session);
				$snapshot = $metadata[DocumentSessionManager::CONTEXT_VO_SAVED_VERSION_ID_KEY] ?? null;
				$snapshot = is_int($snapshot) && $snapshot >= 0 ? $snapshot : null;
				$budget = min($this->recentClosedBudgetSec, $deadline - microtime(true));
				if (!$this->waitForVersion($fileId, $snapshot, $docKey, $budget))
				{
					$result->addError($this->createTimeoutError());
				}

				continue;
			}

			// Each serial request starts after deliveries that arrived while earlier keys were waiting.
			$activeBaseline = DocumentSessionManager::getLatestSavedVersionId($fileId);
			if (File::loadById($fileId) === null)
			{
				return (new Result())->addError(new Error('The document is unavailable.', 'vibeoffice_sync_file_missing'));
			}
			$forcesaveResult = $this->protocolClient->forcesave($docKey, null);
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
			if (!$this->waitForVersion($fileId, $activeBaseline, $docKey, $deadline - microtime(true)))
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
			?? $pool->cloneConnection(ConnectionPool::DEFAULT_CONNECTION_NAME, self::SYNC_LOCK_CONNECTION_NAME)
		;
	}

	/**
	 * A platform reply describes its state, not whether the portal has applied the saved content.
	 */
	private function isKnownSaveOutcome(Result $forcesaveResult): bool
	{
		if ($forcesaveResult->isSuccess())
		{
			return in_array($forcesaveResult->getData()['result'] ?? null, ['queued', self::RESULT_NOT_MODIFIED], true);
		}

		foreach ($forcesaveResult->getErrors() as $error)
		{
			$customData = $error->getCustomData();
			if (!is_array($customData))
			{
				return false;
			}

			$httpStatus = (int)($customData['httpStatus'] ?? 0);
			if (
				$httpStatus !== self::CONFLICT_STATUS
				|| !in_array($customData['platformCode'] ?? null, self::NOTHING_TO_SAVE_CODES, true)
			)
			{
				return false;
			}
		}

		return true;
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

	/** The applied receipt must point to this key's newer file version, including terminal replays. */
	private function hasAppliedVersion(int $fileId, ?int $baseline, string $docKey): bool
	{
		if ($baseline === null)
		{
			return false;
		}
		$keyFilters = [
			Query::filter()->where('HOST_DOC_KEY', $docKey),
			Query::filter()->whereNull('HOST_DOC_KEY')->where('EXTERNAL_HASH', $docKey),
		];
		// Separate lookups preserve the index path for both current and legacy keys.
		foreach ($keyFilters as $keyFilter)
		{
			$row = DeliveryTable::query()
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
				->where('RESULT_VERSION_ID', '>', $baseline)
				->setLimit(1)
				->fetch();
			if ($row)
			{
				return true;
			}
		}

		return false;
	}

	private function isRecentNonActiveSession(DocumentSession $session): bool
	{
		return (new DateTime())->getTimestamp() - $session->getCreateTime()->getTimestamp()
			<= self::RECENT_NON_ACTIVE_SESSION_MAX_AGE_SEC;
	}

	/**
	 * Legacy closed sessions without an immutable baseline cannot confirm delivery and return immediately.
	 *
	 * The interval between checks grows up to a cap: a save that lands quickly - the common case - is
	 * still noticed within the first interval, while a wait that runs on costs a fraction of the reads
	 * a fixed step would. The last sleep never outlives the budget.
	 */
	private function waitForVersion(
		int $fileId,
		?int $baseline,
		string $docKey,
		float $budgetSec,
	): bool
	{
		if ($baseline === null)
		{
			return false;
		}

		$deadline = microtime(true) + $budgetSec;
		$intervalUsec = max(1, $this->pollIntervalMs * 1000);
		$maxIntervalUsec = max(1, $this->pollIntervalMs * self::POLL_INTERVAL_MAX_FACTOR * 1000);

		while (true)
		{
			if ($this->hasAppliedVersion($fileId, $baseline, $docKey))
			{
				return true;
			}

			$remainingUsec = (int)round(($deadline - microtime(true)) * 1000000);
			if ($remainingUsec <= 0)
			{
				return false;
			}

			($this->sleeper)(min($intervalUsec, $remainingUsec));
			$intervalUsec = min($intervalUsec * self::POLL_INTERVAL_GROWTH, $maxIntervalUsec);
		}
	}
}

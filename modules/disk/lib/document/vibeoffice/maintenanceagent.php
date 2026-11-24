<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\DocumentSessionTable;
use Bitrix\Disk\Document\Vibeoffice\Logging\LoggerProvider;
use Bitrix\Disk\Document\Vibeoffice\Models\DocumentStateTable;
use Bitrix\Disk\Document\Vibeoffice\Models\PlatformSessionTable;
use Bitrix\Disk\Document\Vibeoffice\Models\SavedSnapshotTable;
use Bitrix\Disk\Document\Vibeoffice\Webhook\DeliveryTable;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Internals\VersionTable;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Daily cleanup of expired vibeoffice registry rows, finished deliveries and their orphaned blobs.
 *
 * Unfinished saves are out of reach by construction: pending, staged and applied deliveries,
 * their files and live sessions are never selected. An expired document - its state, snapshots and
 * NON_ACTIVE local sessions - goes only when nothing live or unfinished refers to its key.
 */
final class MaintenanceAgent
{
	public const LOCK_NAME = 'disk_vo_maintenance';
	public const BATCH_SIZE = 500;
	public const SOFT_TIME_LIMIT_SECONDS = 20;
	public const PENDING_TTL_SECONDS = 86400;
	public const REFUSED_TTL_SECONDS = 86400;
	public const RETENTION_DAYS = 30;
	/** A delivery that has just finished is left alone: its own writes may not have reached a replica yet. */
	public const STAGED_FILE_MIN_AGE_SECONDS = 300;
	public const OPTION_MODE = 'vibeoffice_maintenance_mode';
	public const OPTION_NEXT_GC = 'vibeoffice_maintenance_next_gc_at';
	public const ENTRY_TIME_LIMIT_SECONDS = 50;
	/** The walk of the expired states goes on from this ID of b_disk_vo_document in the next run. */
	public const OPTION_DOCUMENT_CURSOR = 'vibeoffice_maintenance_document_cursor';

	public const MODE_OFF = 'off';
	public const MODE_APPLY = 'apply';

	/** Reachable from an object or a version: Disk has accepted the blob, the delivery no longer owns it. */
	private const OWNER_DISK = 'disk';
	/** Still pinned by the editing subsystem itself: another delivery or an expected content of a document. */
	private const OWNER_VIBEOFFICE = 'vibeoffice';

	/** The document is behind the walk: removed, kept for a reason of its own or deferred to the next round. */
	private const DOCUMENT_PASSED = 'passed';
	/** The budget ran out before the document could be checked; it waits for the next round. */
	private const DOCUMENT_DEFERRED = 'deferred';
	/** The budget ran out in the middle of the removal; the next run starts with the document. */
	private const DOCUMENT_INTERRUPTED = 'interrupted';

	private const LOGGER_ID = 'disk.vibeoffice.maintenance';
	private const SECONDS_PER_DAY = 86400;

	private float $startedAt = 0.0;
	private float $deadline = 0.0;
	private float $entryDeadline = 0.0;
	private array $sharedAccess = [];
	private array $deleted = [];
	private array $skipped = [];
	private array $categories = [];
	private ?string $currentCategory = null;
	private array $countedOpenFileIds = [];

	public function __construct(
		private readonly int $batchSize = self::BATCH_SIZE,
		private readonly float $softTimeLimitSeconds = self::SOFT_TIME_LIMIT_SECONDS,
		private readonly FileRemoverInterface $fileRemover = new FileRemover(),
		private readonly ?float $absoluteDeadline = null,
	)
	{
	}

	public function run(?DateTime $now = null): Result
	{
		$this->startedAt = hrtime(true) / 1e9;
		$this->entryDeadline = min($this->absoluteDeadline ?? INF, $this->startedAt + self::ENTRY_TIME_LIMIT_SECONDS) - 0.25;
		$this->deadline = $this->entryDeadline;
		$this->deleted = [
			'pending' => 0,
			'terminated' => 0,
			'refused' => 0,
			'deliveries' => 0,
			'files' => 0,
			'detached' => 0,
			'nonActiveSessions' => 0,
			'snapshots' => 0,
			'states' => 0,
		];
		$this->skipped = [
			'busyDocuments' => 0,
			'referenced' => 0,
			'openTransaction' => 0,
			'missingDocumentKey' => 0,
			'invalidStagedFile' => 0,
			'refusedDeletes' => 0,
			'openObligations' => 0,
			'openObligationBytes' => 0,
			'deferredDocuments' => 0,
		];
		$this->categories = [];
		$this->currentCategory = null;
		$this->countedOpenFileIds = [];

		$now ??= new DateTime();
		$this->sharedAccess = (new SharedAccessMaintenance())->run($now, $this->entryDeadline)->getData();
		$mode = self::readMode();
		if ($mode !== self::MODE_APPLY)
		{
			return $this->report($mode, false, true, 'disabled');
		}

		// A replica may still miss the save that accepted a blob, so the whole pass reads from the master.
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			if ((int)Option::get('disk', self::OPTION_NEXT_GC, '0') > time())
			{
				return $this->report($mode, false, true, 'completed');
			}
			if ($this->entryDeadline - hrtime(true) / 1e9 < 1.1)
			{
				return $this->report($mode, false, false, 'time_limit');
			}
			return SqlDeadlineScope::run($this->entryDeadline, fn(): Result => $this->runUnderLock($mode, $now));
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}

	private function runUnderLock(string $mode, DateTime $now): Result
	{
		$connection = null;
		$locked = false;
		$failure = null;
		try
		{
			$connection = $this->createGcLockConnection();
			$locked = $connection->lock(self::LOCK_NAME, 0);
			if (!$locked)
			{
				return $this->report($mode, true, false, 'busy');
			}
			if ((int)Option::get('disk', self::OPTION_NEXT_GC, '0') > time())
			{
				return $this->report($mode, false, true, 'completed');
			}
			if ($this->entryDeadline - hrtime(true) / 1e9 < 1.1)
			{
				return $this->report($mode, false, false, 'time_limit');
			}
			Option::set('disk', self::OPTION_NEXT_GC, (string)(time() + self::SECONDS_PER_DAY));
			$complete = $this->runCategories($now);
		}
		catch (\Throwable $exception)
		{
			$complete = false;
			$failure = new Error('Vibeoffice maintenance failed.', 'maintenance_failed');
			self::logDiagnostic('error', 'vibeoffice maintenance', [
				'operation' => 'run', 'result' => 'error', 'code' => 'maintenance_exception',
				'exception_class' => self::safeExceptionClass($exception),
			]);
		}
		finally
		{
			try
			{
				if ($locked) { $connection->unlock(self::LOCK_NAME); }
			}
			finally { $connection?->disconnect(); }
		}
		$stopReason = $failure ? 'exception' : ($complete ? 'completed' : 'time_limit');
		$result = $this->report($mode, false, $complete, $stopReason);
		return $failure ? $result->addError($failure) : $result;
	}

	/** A dedicated session owns only the global mutex, never business SQL or transactions. */
	private function createGcLockConnection(): Connection
	{
		$primary = Application::getConnection();
		$configuration = array_replace($primary->getConfiguration(), [
			'options' => 0, 'persistent' => false, 'include_after_connected' => '',
		]);
		if ($primary->getType() === 'pgsql')
		{
			$charset = $primary->queryScalar("SELECT current_setting('client_encoding')");
			if (!is_string($charset) || preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,31}$/D', $charset) !== 1)
			{
				throw new \RuntimeException('The maintenance connection encoding is unsupported.');
			}
			// libpq reuses identical DSNs; a distinct safe application name keeps the primary session intact.
			$configuration['charset'] = $charset . ' --application_name=disk_vo_gc_' . bin2hex(random_bytes(8));
		}
		return new ($primary::class)($configuration);
	}

	public static function readMode(): string
	{
		$mode = (string)Option::get('disk', self::OPTION_MODE, self::MODE_APPLY);
		if ($mode === self::MODE_APPLY)
		{
			return self::MODE_APPLY;
		}
		if ($mode !== self::MODE_OFF)
		{
			self::logDiagnostic('info', 'vibeoffice maintenance', [
				'operation' => 'run',
				'result' => 'error',
				'code' => 'maintenance_failed',
				'mode' => 'unknown',
			]);
		}

		return self::MODE_OFF;
	}

	public static function getPendingThreshold(DateTime $now): DateTime
	{
		return DateTime::createFromTimestamp($now->getTimestamp() - self::PENDING_TTL_SECONDS);
	}

	public static function getRetentionThreshold(DateTime $now): DateTime
	{
		return DateTime::createFromTimestamp($now->getTimestamp() - self::RETENTION_DAYS * self::SECONDS_PER_DAY);
	}

	public static function getStagedFileThreshold(DateTime $now): DateTime
	{
		return DateTime::createFromTimestamp($now->getTimestamp() - self::STAGED_FILE_MIN_AGE_SECONDS);
	}

	/**
	 * Each category owns its share of the soft limit, so an early one cannot leave a late one without a
	 * pass. A category that finishes ahead of its deadline leaves the rest of its share to the next ones.
	 */
	private function runCategories(DateTime $now): bool
	{
		$hasRegistryRows = $this->tableHasRows(PlatformSessionTable::getTableName());
		$categories = [
			'pending' => fn(): bool => !$hasRegistryRows || $this->deleteExpiredPending($now),
			'terminated' => fn(): bool => !$hasRegistryRows || $this->deleteExpiredTerminated($now),
			'refused' => fn(): bool => !$hasRegistryRows || $this->deleteExpiredRefused($now),
			'stagedFiles' => fn(): bool => $this->releaseStagedFiles($now),
			'deliveries' => fn(): bool => $this->deleteExpiredDeliveries($now),
			'expiredDocuments' => fn(): bool => $this->deleteExpiredDocuments($now),
			'sharedContent' => fn(): bool => $this->deleteSharedContent($now, 0),
			'sharedFences' => fn(): bool => $this->deleteSharedContent($now, 1),
			'compactMarkers' => fn(): bool => $this->deleteCompactMarkers(),
		];
		$gcStarted = hrtime(true) / 1e9;
		$share = max(0.0, min($this->softTimeLimitSeconds, $this->entryDeadline - $gcStarted)) / count($categories);
		$budget = 0.0;
		$complete = true;
		foreach ($categories as $name => $category)
		{
			$this->currentCategory = $name;
			$budget += $share;
			$this->deadline = min($this->entryDeadline, $gcStarted + $budget);
			$this->categories[$name] = !$this->timeIsUp() && $category();
			$complete = $complete && $this->categories[$name];
		}

		return $complete;
	}

	/**
	 * A pending row with a token is an open whose request may have reached the platform: its
	 * outcome stays unknown at any age and whatever the local session became, so it is never swept.
	 */
	private function deleteExpiredPending(DateTime $now): bool
	{
		return $this->deleteSessions(
			PlatformSessionTable::STATUS_PENDING,
			'CREATE_TIME',
			self::getPendingThreshold($now),
			'pending',
			true,
			true,
		);
	}

	private function deleteExpiredRefused(DateTime $now): bool
	{
		$threshold = DateTime::createFromTimestamp($now->getTimestamp() - self::REFUSED_TTL_SECONDS);
		$lastId = 0;
		$entity = PlatformSessionTable::getEntity();
		$connection = $entity->getConnection();
		while (true)
		{
			$filter = Query::filter()
				->where('STATUS', PlatformSessionTable::STATUS_REFUSED)
				->where(Query::filter()->logic('or')->whereNull('API_SESSION_ID')->where('API_SESSION_ID', ''))
				->where('UPDATE_TIME', '<', $threshold)
			;
			$query = PlatformSessionTable::query()->setSelect(['ID'])
				->where(clone $filter)->where('ID', '>', $lastId)
				->setOrder(['ID' => 'ASC'])->setLimit($this->batchSize)
			;
			$rows = $this->legacyRegistryQuery($query)->fetchAll();
			foreach ($rows as $index => $row)
			{
				if ($this->batchIsOver($index))
				{
					return false;
				}
				$lastId = (int)$row['ID'];
				$where = Query::buildFilterSql($entity, (clone $filter)->where('ID', $lastId));
				$connection->queryExecute('DELETE FROM ' . PlatformSessionTable::getTableName() . ' WHERE ' . $where);
				$this->deleted['refused'] += $connection->getAffectedRowsCount();
			}
			if (count($rows) < $this->batchSize)
			{
				return true;
			}
			if ($this->timeIsUp())
			{
				return false;
			}
		}
	}

	private function deleteExpiredTerminated(DateTime $now): bool
	{
		return $this->deleteSessions(
			PlatformSessionTable::STATUS_TERMINATED,
			'UPDATE_TIME',
			self::getRetentionThreshold($now),
			'terminated',
		);
	}

	private function deleteSessions(
		string $status,
		string $timeField,
		DateTime $threshold,
		string $counter,
		bool $keepActiveLocalSessions = false,
		bool $keepOpenTokens = false,
	): bool
	{
		$lastId = 0;
		while (true)
		{
			$query = PlatformSessionTable::query()
				->setSelect(['ID', 'DOCUMENT_SESSION_ID', 'OPEN_TOKEN', 'STATUS'])
				->where('STATUS', $status)
				->where($timeField, '<', $threshold)
				->where('ID', '>', $lastId)
				->setOrder(['ID' => 'ASC'])
				->setLimit($this->batchSize)
			;
			if ($keepOpenTokens)
			{
				$query->whereNull('OPEN_TOKEN');
			}
			$rows = $this->legacyRegistryQuery($query)->fetchAll();
			if (!$rows)
			{
				return true;
			}
			$activeSessionIds = [];
			if ($keepActiveLocalSessions)
			{
				$sessionIds = array_values(array_filter(array_map(
					static fn(array $row): int => (int)$row['DOCUMENT_SESSION_ID'],
					$rows,
				)));
				if ($sessionIds !== [])
				{
					$activeRows = DocumentSessionTable::query()
						->setSelect(['ID'])
						->whereIn('ID', array_values(array_unique($sessionIds)))
						->where('STATUS', DocumentSession::STATUS_ACTIVE)
						->fetchAll()
					;
					$activeSessionIds = array_fill_keys(array_column($activeRows, 'ID'), true);
				}
			}
			// The registry token is a part of the durable carrier of its attempt.
			$protectedTokens = OpenDebtCarrier::filterProtectedTokens(array_filter(
				$rows,
				static fn(array $row): bool => is_string($row['OPEN_TOKEN']) && $row['OPEN_TOKEN'] !== '',
			));
			foreach ($rows as $index => $row)
			{
				if ($this->batchIsOver($index))
				{
					return false;
				}
				$lastId = (int)$row['ID'];
				if (isset($activeSessionIds[(int)$row['DOCUMENT_SESSION_ID']]))
				{
					continue;
				}
				if (is_string($row['OPEN_TOKEN']) && isset($protectedTokens[$row['OPEN_TOKEN']]))
				{
					continue;
				}
				if ($this->rowIsDeleted(PlatformSessionTable::delete($lastId), 'session', $lastId))
				{
					$this->deleted[$counter]++;
				}
			}
			if (count($rows) < $this->batchSize)
			{
				return true;
			}
			if ($this->timeIsUp())
			{
				return false;
			}
		}
	}

	private function releaseStagedFiles(DateTime $now): bool
	{
		$threshold = self::getStagedFileThreshold($now);
		$lastId = 0;
		while (true)
		{
			$query = DeliveryTable::query()
				->setSelect(['ID', 'HOST_DOC_KEY', 'STAGED_FILE_ID', 'OUTCOME', 'SNAPSHOT_SEQ'])
				->where('STATUS', DeliveryTable::STATUS_DONE)
				->where(Query::filter()->logic('or')->whereNull('OUTCOME')
					->where('OUTCOME', DeliveryTable::OUTCOME_APPLIED)
					->where('OUTCOME', DeliveryTable::OUTCOME_REFUSED)
					->where('OUTCOME', DeliveryTable::OUTCOME_IGNORED))
				->whereNotNull('STAGED_FILE_ID')
				->where('PROCESSED_TIME', '<', $threshold)
				->where('ID', '>', $lastId)
				->setOrder(['ID' => 'ASC'])
				->setLimit($this->batchSize)
			;
			$rows = $this->excludeSharedKeys($query)->fetchAll();
			if (!$rows)
			{
				return true;
			}
			$owners = $this->readFileOwners(array_column($rows, 'STAGED_FILE_ID'));
			foreach ($rows as $index => $row)
			{
				if ($this->batchIsOver($index))
				{
					return false;
				}
				$lastId = (int)$row['ID'];
				if ($this->isUnappliedOutcome($row) && !$this->hasNewerAppliedSnapshot($row))
				{
					$this->skipped['openObligations']++;
					$fileId = (int)$row['STAGED_FILE_ID'];
					if ($fileId > 0 && !isset($this->countedOpenFileIds[$fileId]))
					{
						$this->countedOpenFileIds[$fileId] = true;
						$file = \CFile::GetFileArray($fileId);
						$this->skipped['openObligationBytes'] += (int)($file['FILE_SIZE'] ?? 0);
					}

					continue;
				}
				$this->releaseStagedFile($row, $owners);
			}
			if (count($rows) < $this->batchSize)
			{
				return true;
			}
			if ($this->timeIsUp())
			{
				return false;
			}
		}
	}

	private function releaseStagedFile(array $row, array $owners): void
	{
		$ledgerId = (int)$row['ID'];
		$fileId = (int)$row['STAGED_FILE_ID'];
		if ($fileId <= 0)
		{
			// Nothing was asked about this blob, so the row must not look like a busy one in the report.
			$this->skipped['invalidStagedFile']++;

			return;
		}
		$owner = $owners[$fileId] ?? null;
		if ($this->isUnappliedOutcome($row))
		{
			$locked = DocumentStateRepository::runUnderDocumentLock(
				(string)$row['HOST_DOC_KEY'],
				fn() => $this->releaseLockedStagedFile($ledgerId, $fileId, (string)$row['OUTCOME']),
			);
			if (!$locked)
			{
				$this->skipped['busyDocuments']++;
			}

			return;
		}
		if ($owner === self::OWNER_DISK)
		{
			$this->detachStagedFile($ledgerId);

			return;
		}
		if ($owner !== null)
		{
			$this->skipped['referenced']++;

			return;
		}
		$hostDocKey = (string)($row['HOST_DOC_KEY'] ?? '');
		if ($hostDocKey === '')
		{
			// Without a document there is no lock to take, so the orphan blob waits for a human.
			$this->skipped['missingDocumentKey']++;

			return;
		}

		$locked = DocumentStateRepository::runUnderDocumentLock(
			$hostDocKey,
			fn() => $this->releaseLockedStagedFile($ledgerId, $fileId),
		);
		if (!$locked)
		{
			$this->skipped['busyDocuments']++;
		}
	}

	private function releaseLockedStagedFile(int $ledgerId, int $fileId, ?string $expectedOutcome = null): void
	{
		$fresh = DeliveryTable::getById($ledgerId)->fetch();
		if (!$fresh || $this->isProtectedKey((string)$fresh['HOST_DOC_KEY'])
				|| $fresh['STATUS'] !== DeliveryTable::STATUS_DONE
			|| (int)($fresh['STAGED_FILE_ID'] ?? 0) !== $fileId
			|| ($expectedOutcome !== null && $fresh['OUTCOME'] !== $expectedOutcome))
		{
			return;
		}
		if ($this->isUnappliedOutcome($fresh) && !$this->hasNewerAppliedSnapshot($fresh))
		{
			$this->skipped['openObligations']++;

			return;
		}
		$owner = $this->readFileOwners([$fileId])[$fileId] ?? null;
		if ($owner === self::OWNER_DISK)
		{
			$this->detachStagedFile($ledgerId);

			return;
		}
		if ($owner !== null)
		{
			if ($expectedOutcome !== null)
			{
				$this->detachStagedFile($ledgerId);
			}
			else
			{
				$this->skipped['referenced']++;
			}

			return;
		}
		if (!$this->fileRemover->remove($fileId))
		{
			$this->skipped['openTransaction']++;
			self::logDiagnostic(
				'error',
				'vibeoffice maintenance',
				[
					'operation' => 'cleanup',
					'result' => 'error',
					'code' => 'maintenance_failed',
					'file' => $fileId,
					'delivery' => $ledgerId,
				],
			);

			return;
		}
		$this->deleted['files']++;
		$this->clearStagedFile($ledgerId);
	}

	private function detachStagedFile(int $ledgerId): void
	{
		if ($this->clearStagedFile($ledgerId))
		{
			$this->deleted['detached']++;
		}
	}

	/** A handler may refuse a delete, and a row that survived must not be counted as removed. */
	private function rowIsDeleted(Result $result, string $entity, int $id): bool
	{
		if ($result->isSuccess())
		{
			return true;
		}
		$this->skipped['refusedDeletes']++;
		self::logDiagnostic('error', 'vibeoffice maintenance', [
			'operation' => 'delete',
			'result' => 'error',
			'code' => 'maintenance_failed',
			'entity' => in_array($entity, ['session', 'delivery'], true) ? $entity : 'unknown',
			'id' => max(0, $id),
		]);

		return false;
	}

	private function clearStagedFile(int $ledgerId): bool
	{
		$result = DeliveryTable::update($ledgerId, ['STAGED_FILE_ID' => null]);
		if ($result->isSuccess())
		{
			return true;
		}
		self::logDiagnostic('error', 'vibeoffice maintenance', [
			'operation' => 'clear_stage',
			'result' => 'error',
			'code' => 'maintenance_failed',
			'delivery' => max(0, $ledgerId),
		]);

		return false;
	}

	private function deleteExpiredDeliveries(DateTime $now): bool
	{
		$threshold = self::getRetentionThreshold($now);
		$lastId = 0;
		while (true)
		{
			$query = DeliveryTable::query()
				->setSelect(['ID', 'HOST_DOC_KEY', 'STAGED_FILE_ID', 'OUTCOME', 'SNAPSHOT_SEQ'])
				->where('STATUS', DeliveryTable::STATUS_DONE)
				->where(Query::filter()->logic('or')->whereNull('OUTCOME')
					->where('OUTCOME', DeliveryTable::OUTCOME_APPLIED)
					->where('OUTCOME', DeliveryTable::OUTCOME_REFUSED)
					->where('OUTCOME', DeliveryTable::OUTCOME_IGNORED))
				->where('PROCESSED_TIME', '<', $threshold)
				->where('ID', '>', $lastId)
				->setOrder(['ID' => 'ASC'])
				->setLimit($this->batchSize)
			;
			$rows = $this->excludeSharedKeys($query)->fetchAll();
			if (!$rows)
			{
				return true;
			}
			$owners = $this->readFileOwners(array_column($rows, 'STAGED_FILE_ID'));
			foreach ($rows as $index => $row)
			{
				if ($this->batchIsOver($index))
				{
					return false;
				}
				$lastId = (int)$row['ID'];
				if ($this->isUnappliedOutcome($row) && !$this->hasNewerAppliedSnapshot($row))
				{
					if ((int)($row['STAGED_FILE_ID'] ?? 0) <= 0)
					{
						$this->skipped['openObligations']++;
					}

					continue;
				}
				$fileId = (int)($row['STAGED_FILE_ID'] ?? 0);
				if ($fileId > 0 && ($owners[$fileId] ?? null) !== self::OWNER_DISK)
				{
					continue;
				}
				if ($this->isUnappliedOutcome($row))
				{
					$deleted = false;
					$locked = DocumentStateRepository::runUnderDocumentLock(
						(string)$row['HOST_DOC_KEY'],
						function () use ($lastId, &$deleted): void {
							$fresh = DeliveryTable::getById($lastId)->fetch();
							$deleted = $fresh && !$this->isProtectedKey((string)$fresh['HOST_DOC_KEY'])
								&& $fresh['STATUS'] === DeliveryTable::STATUS_DONE
								&& $this->isUnappliedOutcome($fresh)
								&& $this->hasNewerAppliedSnapshot($fresh)
								&& (int)$fresh['STAGED_FILE_ID'] <= 0
								&& $this->rowIsDeleted(DeliveryTable::delete($lastId), 'delivery', $lastId);
						},
					);
					if (!$locked)
					{
						$this->skipped['busyDocuments']++;
					}
				}
				elseif ($row['OUTCOME'] === DeliveryTable::OUTCOME_APPLIED)
				{
					$deleted = $this->deleteExpiredAppliedDelivery($lastId, $threshold);
				}
				else
				{
					$deleted = $this->rowIsDeleted(DeliveryTable::delete($lastId), 'delivery', $lastId);
				}
				if ($deleted)
				{
					$this->deleted['deliveries']++;
				}
			}
			if (count($rows) < $this->batchSize)
			{
				return true;
			}
			if ($this->timeIsUp())
			{
				return false;
			}
		}
	}

	private function deleteExpiredAppliedDelivery(int $ledgerId, DateTime $threshold): bool
	{
		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$expired = DocumentStateRepository::fetchForUpdate(
				$this->excludeSharedKeys(DeliveryTable::query()->setSelect(['ID']))
					->where('ID', $ledgerId)
					->where('STATUS', DeliveryTable::STATUS_DONE)
					->where('OUTCOME', DeliveryTable::OUTCOME_APPLIED)
					->where('PROCESSED_TIME', '<', $threshold),
			);
			if ($expired && !$this->rowIsDeleted(DeliveryTable::delete($ledgerId), 'delivery', $ledgerId))
			{
				self::rollbackOwnLevel($connection);

				return false;
			}
			$connection->commitTransaction();

			return $expired !== null;
		}
		catch (\Throwable $exception)
		{
			self::rollbackOwnLevel($connection);

			throw $exception;
		}
	}

	/** Empty legacy tables need no optional ORM map; any row keeps the full schema-aware path. */
	private function tableHasRows(string $table): bool
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$sql = 'SELECT ' . $helper->quote('ID') . ' FROM ' . $helper->quote($table);

		return $connection->query($helper->getTopSql($sql, 1))->fetch() !== false;
	}

	/**
	 * A portal without VibeOffice pays one primary key probe. The rest walks the states by ID, so the
	 * shared session table is only read by the indexes of a single document.
	 *
	 * The walk goes on from the stored cursor: documents kept for a reason of their own must not hold it at
	 * the head of the table run after run. The end of the table brings it round to the start once a run,
	 * up to the cursor the run began with.
	 */
	private function deleteExpiredDocuments(DateTime $now): bool
	{
		if (!$this->tableHasRows(DocumentStateTable::getTableName()))
		{
			return true;
		}

		$threshold = self::getRetentionThreshold($now);
		$startId = max(0, (int)Option::get('disk', self::OPTION_DOCUMENT_CURSOR, '0'));
		$cursor = $startId;
		$wrapped = false;
		$complete = false;
		while (true)
		{
			$query = DocumentStateTable::query()
				->setSelect(['ID'])
				->where('UPDATED_TIME', '<', $threshold)
				->where('ID', '>', $cursor)
				->setOrder(['ID' => 'ASC'])
				->setLimit($this->batchSize)
			;
			if ($wrapped)
			{
				$query->where('ID', '<=', $startId);
			}
			$rows = $this->excludeSharedKeys($query)->fetchAll();
			$stopped = false;
			foreach ($rows as $index => $row)
			{
				if ($this->batchIsOver($index))
				{
					$stopped = true;

					break;
				}
				$stateId = (int)$row['ID'];
				$outcome = $this->deleteExpiredDocument($stateId, $threshold);
				$cursor = $outcome === self::DOCUMENT_INTERRUPTED ? $stateId - 1 : $stateId;
				if ($outcome !== self::DOCUMENT_PASSED)
				{
					$stopped = true;

					break;
				}
			}
			if ($stopped)
			{
				break;
			}
			if (count($rows) < $this->batchSize)
			{
				if ($wrapped || $startId === 0)
				{
					$complete = true;
					$cursor = 0;

					break;
				}
				$wrapped = true;
				$cursor = 0;
			}
			if ($this->timeIsUp())
			{
				break;
			}
		}

		if ($cursor !== $startId)
		{
			Option::set('disk', self::OPTION_DOCUMENT_CURSOR, (string)$cursor);
		}

		return $complete;
	}

	/**
	 * One document, one transaction a run: the state is reread under its row lock before anything goes.
	 * What a run removed before its budget ran out is committed, the rest is left to the next run.
	 *
	 * @return string one of the DOCUMENT_* outcomes
	 */
	private function deleteExpiredDocument(int $stateId, DateTime $threshold): string
	{
		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$deleted = $this->deleteLockedExpiredDocument($stateId, $threshold);
			if ($deleted === null)
			{
				self::rollbackOwnLevel($connection);

				return self::DOCUMENT_PASSED;
			}
			if ($deleted['deferred'])
			{
				// Nothing has been removed before the check, the rollback only releases the row lock.
				self::rollbackOwnLevel($connection);
				$this->skipped['deferredDocuments']++;

				return self::DOCUMENT_DEFERRED;
			}
			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			self::rollbackOwnLevel($connection);
			$this->skipped['refusedDeletes']++;
			self::logDiagnostic('error', 'vibeoffice maintenance', [
				'operation' => 'delete',
				'result' => 'error',
				'code' => 'maintenance_failed',
				'entity' => 'document',
				'id' => max(0, $stateId),
				'exception_class' => self::safeExceptionClass($exception),
			]);

			return self::DOCUMENT_PASSED;
		}

		$complete = $deleted['complete'];
		unset($deleted['complete'], $deleted['deferred']);
		foreach ($deleted as $counter => $count)
		{
			$this->deleted[$counter] += $count;
		}

		return $complete ? self::DOCUMENT_PASSED : self::DOCUMENT_INTERRUPTED;
	}

	/**
	 * The sessions and the snapshots go page by page, and the budget is checked between the pages. A
	 * document cut short keeps its state and the head of its snapshots, so the next pass finds it by the
	 * same threshold, rechecks it and goes on. The state goes last.
	 *
	 * @return array|null the deleted rows by counter with the completeness and deferral flags, null when a
	 *     delete was refused
	 */
	private function deleteLockedExpiredDocument(int $stateId, DateTime $threshold): ?array
	{
		$deleted = [
			'nonActiveSessions' => 0,
			'snapshots' => 0,
			'states' => 0,
			'complete' => true,
			'deferred' => false,
		];
		// The age is rechecked under the row lock: a writer may have touched the document meanwhile.
		$state = DocumentStateRepository::fetchForUpdate(
			DocumentStateTable::query()
				->setSelect(SharedEditingSchema::documentFields())
				->where('ID', $stateId)
				->where('UPDATED_TIME', '<', $threshold),
		);
		if (!$state || $this->isProtectedKey((string)$state['HOST_DOC_KEY']))
		{
			return $deleted;
		}
		$inUse = $this->isDocumentInUse($state, $threshold);
		if ($inUse === null)
		{
			$deleted['deferred'] = true;

			return $deleted;
		}
		if ($inUse)
		{
			return $deleted;
		}

		$key = (string)$state['HOST_DOC_KEY'];
		$lastSessionId = 0;
		do
		{
			if ($this->timeIsUp())
			{
				$deleted['complete'] = false;

				return $deleted;
			}
			$page = $this->findDocumentSessionPage($state, DocumentSession::STATUS_NON_ACTIVE, $lastSessionId);
			foreach ($page['ids'] as $sessionId)
			{
				if (!$this->rowIsDeleted(DocumentSessionTable::delete($sessionId), 'document session', $sessionId))
				{
					return null;
				}
				$deleted['nonActiveSessions']++;
			}
			$lastSessionId = $page['lastId'];
		}
		while ($page['full']);

		$pageSize = max(1, $this->batchSize);
		$lastSeq = null;
		while (true)
		{
			if ($this->timeIsUp())
			{
				$deleted['complete'] = false;

				return $deleted;
			}
			// One row past the page tells the last page apart, so the head of the history is never removed
			// by a page cut short: it goes together with the state.
			$query = SavedSnapshotTable::query()
				->setSelect(['HOST_DOC_KEY', 'SNAPSHOT_SEQ'])
				->where('HOST_DOC_KEY', $key)
				->setOrder(['SNAPSHOT_SEQ' => 'ASC'])
				->setLimit($pageSize + 1)
			;
			// The rows deleted in this transaction stay in the index until purge, so the next page starts
			// past them instead of rescanning them from the start of the key.
			if ($lastSeq !== null)
			{
				$query->where('SNAPSHOT_SEQ', '>', $lastSeq);
			}
			$snapshots = $query->fetchAll();
			$lastPage = count($snapshots) <= $pageSize;
			foreach (array_slice($snapshots, 0, $pageSize) as $snapshot)
			{
				$lastSeq = (int)$snapshot['SNAPSHOT_SEQ'];
				$primary = ['HOST_DOC_KEY' => $key, 'SNAPSHOT_SEQ' => $lastSeq];
				if (!$this->rowIsDeleted(SavedSnapshotTable::delete($primary), 'snapshot', $stateId))
				{
					return null;
				}
				$deleted['snapshots']++;
			}
			if ($lastPage)
			{
				break;
			}
		}

		if (!$this->rowIsDeleted(DocumentStateTable::delete($stateId), 'document state', $stateId))
		{
			return null;
		}
		$deleted['states']++;

		return $deleted;
	}

	/**
	 * The first page of the ACTIVE sessions is always read, the next ones only within the budget.
	 *
	 * @return bool|null true when the key is in use, false when it is free, null when the check was cut
	 *     short by the budget: an incomplete check proves nothing
	 */
	private function isDocumentInUse(array $state, DateTime $threshold): ?bool
	{
		$key = (string)$state['HOST_DOC_KEY'];
		$livePlatformSession = PlatformSessionTable::query()
			->setSelect(['ID'])
			->where('HOST_DOC_KEY', $key)
			->where(Query::filter()->logic('or')
				->whereIn('STATUS', [PlatformSessionTable::STATUS_PENDING, PlatformSessionTable::STATUS_ACTIVE])
				->where(Query::filter()
					->where('STATUS', PlatformSessionTable::STATUS_TERMINATED)
					->where('UPDATE_TIME', '>=', $threshold)
				)
			)
			->setLimit(1)
			->fetch()
		;
		if ($livePlatformSession)
		{
			return true;
		}

		$unfinished = Query::filter()->logic('or')->whereIn('STATUS', [
			DeliveryTable::STATUS_PENDING,
			DeliveryTable::STATUS_STAGED,
			DeliveryTable::STATUS_APPLIED,
		]);
		if (HostDocumentKey::isStable($key))
		{
			$unfinished->where(
				Query::filter()
					->where('EVENT', Webhook\WebhookProcessor::EVENT_SAVED)
					->where('STATUS', DeliveryTable::STATUS_DONE)
					->where('OUTCOME', DeliveryTable::OUTCOME_APPLIED)
					->where('NOTIFIED', 0)
					->where('SNAPSHOT_SEQ', '>', 0)
					->where('RESULT_VERSION_ID', '>', 0)
					->where('RESULT_OBJECT_ID', (int)$state['OBJECT_ID'])
					->where('PROCESSED_TIME', '>=', $threshold)
					->where(Query::filter()->logic('or')->whereNull('REASON')->where('REASON', '')),
			);
		}
		$unfinishedDelivery = DeliveryTable::query()
			->setSelect(['ID'])
			->where('HOST_DOC_KEY', $key)
			->where($unfinished)
			->setLimit(1)
			->fetch()
		;
		if ($unfinishedDelivery)
		{
			return true;
		}

		$lastSessionId = 0;
		do
		{
			if ($this->timeIsUp())
			{
				return null;
			}
			$page = $this->findDocumentSessionPage($state, DocumentSession::STATUS_ACTIVE, $lastSessionId);
			if ($page['ids'] !== [])
			{
				return true;
			}
			$lastSessionId = $page['lastId'];
		}
		while ($page['full']);

		return false;
	}

	/**
	 * One keyset page of the local sessions of a document key: a legacy key is the local hash, a stable
	 * one is pinned in the CONTEXT of the sessions of its object, so the key is resolved here.
	 *
	 * @return array{ids: int[], lastId: int, full: bool} the sessions of the key, the last ID read
	 *     and whether a next page may exist
	 */
	private function findDocumentSessionPage(array $state, int $status, int $afterId): array
	{
		$key = (string)$state['HOST_DOC_KEY'];
		$stable = $state['KEY_MODE'] === HostDocumentKey::MODE_STABLE;
		$pageSize = max(1, $this->batchSize);
		$version = SharedSessionScope::version($state);
		if (!in_array($version, [0, 1], true))
		{
			return ['ids' => [], 'lastId' => $afterId, 'full' => false];
		}
		$scopeFilter = \Bitrix\Main\ORM\Query\Query::filter()
			->where('SERVICE', DocumentService::Vibeoffice->value)->where('STATUS', $status)->where('ID', '>', $afterId)
		;
		if ($version === 1)
		{
			$scopeFilter->whereIn('ID', Models\PlatformSessionTable::query()
				->setSelect(['DOCUMENT_SESSION_ID'])->where('HOST_DOC_KEY', $key)->whereNotNull('DOCUMENT_SESSION_ID'));
		}
		else
		{
			$scopeFilter->where($stable ? 'OBJECT_ID' : 'EXTERNAL_HASH', $stable ? (int)$state['OBJECT_ID'] : $key);
		}
		$sessions = DocumentSession::getModelList([
			'select' => ['*'],
			'filter' => $scopeFilter,
			'order' => ['ID' => 'ASC'],
			'limit' => $pageSize,
		]);

		$matchedIds = $version === 1 ? array_fill_keys(array_map(
			static fn(DocumentSession $session): int => (int)$session->getId(),
			SharedSessionScope::filter($sessions, $state),
		), true) : [];

		$ids = [];
		$lastId = $afterId;
		$read = 0;
		foreach ($sessions as $session)
		{
			$read++;
			$lastId = (int)$session->getId();
			if (HostDocumentKey::resolve($session) === $key
				&& ($version === 0 || isset($matchedIds[(int)$session->getId()])))
			{
				$ids[] = $lastId;
			}
		}

		return ['ids' => $ids, 'lastId' => $lastId, 'full' => $read >= $pageSize];
	}

	/** The core undoes a nested level on its own and only then reports it by throwing. */
	private static function rollbackOwnLevel(Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (TransactionException $exception)
		{
			if ($exception->getMessage() !== 'Nested rollbacks are unsupported.')
			{
				throw $exception;
			}
		}
	}

	private function isUnappliedOutcome(array $row): bool
	{
		return in_array($row['OUTCOME'] ?? null, [DeliveryTable::OUTCOME_REFUSED, DeliveryTable::OUTCOME_IGNORED], true);
	}

	private function hasNewerAppliedSnapshot(array $row): bool
	{
		$key = (string)($row['HOST_DOC_KEY'] ?? '');
		if ($key === '' || $row['SNAPSHOT_SEQ'] === null)
		{
			return false;
		}

		return (bool)SavedSnapshotTable::query()
			->setSelect(['HOST_DOC_KEY'])
			->where('HOST_DOC_KEY', $key)
			->where('SNAPSHOT_SEQ', '>', (int)$row['SNAPSHOT_SEQ'])
			->whereNotNull('DELIVERY_ID')
			->setLimit(1)
			->fetch()
		;
	}

	/**
	 * Who holds each blob of the batch. Disk wins over vibeoffice: a blob an object or a version points
	 * at has been accepted by Disk and is nothing the delivery may free.
	 *
	 * The answer has to be exhaustive, so it is never cut short by the soft time limit: a lookup that
	 * stopped halfway would report a held blob as free and authorise an irreversible delete.
	 *
	 * @param array<int|string|null> $fileIds
	 * @return array<int, string>
	 */
	private function readFileOwners(array $fileIds): array
	{
		$known = [];
		foreach ($fileIds as $fileId)
		{
			$fileId = (int)$fileId;
			if ($fileId > 0)
			{
				$known[$fileId] = true;
			}
		}
		if (!$known)
		{
			return [];
		}

		$owners = [];
		$this->readColumnOwners(
			$owners,
			$known,
			ObjectTable::class,
			['FILE_ID', 'VIEW_ID', 'PREVIEW_ID'],
			self::OWNER_DISK,
		);
		$this->readColumnOwners($owners, $known, VersionTable::class, ['FILE_ID', 'VIEW_ID'], self::OWNER_DISK);
		$this->readColumnOwners(
			$owners,
			$known,
			DocumentStateTable::class,
			['EXPECTED_FILE_ID'],
			self::OWNER_VIBEOFFICE,
		);
		$this->readSharedStageOwners($owners, $known);

		return $owners;
	}

	/**
	 * One column, one query: several columns of a table joined by OR use none of their indexes, and the
	 * pass walks the largest tables of Disk every day. A blob Disk already holds leaves the remaining
	 * lookups, nothing can take that ownership from it.
	 *
	 * @param class-string<\Bitrix\Main\ORM\Data\DataManager> $table
	 * @param list<string> $fields
	 */
	private function readColumnOwners(array &$owners, array $known, string $table, array $fields, string $owner): void
	{
		foreach ($fields as $field)
		{
			$ids = self::idsWithoutDiskOwner($known, $owners);
			if (!$ids)
			{
				return;
			}
			$rows = $table::query()->setSelect([$field])->whereIn($field, $ids)->fetchAll();
			$this->markOwners($owners, $known, $rows, [$field], $owner);
		}
	}

	/**
	 * A delivery counts as a foreign holder only when the blob is staged by more than one row, because the
	 * row asking about it is counted as well.
	 */
	private function readSharedStageOwners(array &$owners, array $known): void
	{
		$ids = self::idsWithoutDiskOwner($known, $owners);
		if (!$ids)
		{
			return;
		}
		$stages = DeliveryTable::query()
			->setSelect(['STAGED_FILE_ID'])
			->registerRuntimeField('HOLDERS', new ExpressionField('HOLDERS', 'COUNT(%s)', 'ID'))
			->addSelect('HOLDERS')
			->whereIn('STAGED_FILE_ID', $ids)
			->setGroup(['STAGED_FILE_ID'])
			->fetchAll()
		;
		$shared = array_filter($stages, static fn(array $stage): bool => (int)$stage['HOLDERS'] > 1);
		$this->markOwners($owners, $known, $shared, ['STAGED_FILE_ID'], self::OWNER_VIBEOFFICE);
	}

	/** @return list<int> */
	private static function idsWithoutDiskOwner(array $known, array $owners): array
	{
		$ids = [];
		foreach (array_keys($known) as $fileId)
		{
			if (($owners[$fileId] ?? null) !== self::OWNER_DISK)
			{
				$ids[] = $fileId;
			}
		}

		return $ids;
	}

	private function markOwners(array &$owners, array $known, array $rows, array $fields, string $owner): void
	{
		foreach ($rows as $row)
		{
			foreach ($fields as $field)
			{
				$fileId = (int)($row[$field] ?? 0);
				if (isset($known[$fileId]) && ($owners[$fileId] ?? null) !== self::OWNER_DISK)
				{
					$owners[$fileId] = $owner;
				}
			}
		}
	}

	private function legacyRegistryQuery(Query $query): Query
	{
		if (!SharedEditingUsage::hasBeenUsed()) { return $query; }
		if (in_array('PLATFORM_USER_ID', SharedEditingSchema::sessionFields(), true))
		{
			$query->where(Query::filter()->logic('or')->whereNull('PLATFORM_USER_ID')->where('PLATFORM_USER_ID', ''));
		}
		return $this->excludeSharedKeys($query);
	}

	private function excludeSharedKeys(Query $query): Query
	{
		if (!SharedEditingUsage::hasBeenUsed()) { return $query; }
		$protectedKeys = [];
		if (in_array('EDIT_SCOPE_VERSION', SharedEditingSchema::documentFields(), true))
		{
			$protectedKeys[] = DocumentStateTable::query()->setSelect(['HOST_DOC_KEY'])
				->where('EDIT_SCOPE_VERSION', '!=', 0);
		}
		if (in_array('PLATFORM_USER_ID', SharedEditingSchema::sessionFields(), true))
		{
			$protectedKeys[] = PlatformSessionTable::query()->setSelect(['HOST_DOC_KEY'])
				->whereNotNull('PLATFORM_USER_ID')->where('PLATFORM_USER_ID', '!=', '');
		}
		foreach ($protectedKeys as $keys)
		{
			$unbound = Query::filter()->whereNull('HOST_DOC_KEY');
			if ($query->getEntity()->hasField('EXTERNAL_HASH'))
			{
				$unbound->where(Query::filter()->logic('or')->whereNull('EXTERNAL_HASH')
					->whereNotIn('EXTERNAL_HASH', clone $keys));
			}
			$query->where(Query::filter()->logic('or')->where($unbound)->whereNotIn('HOST_DOC_KEY', $keys));
		}
		return $query;
	}

	private function isProtectedKey(string $key): bool
	{
		if (!SharedEditingUsage::hasBeenUsed()) { return false; }
		if (in_array('EDIT_SCOPE_VERSION', SharedEditingSchema::documentFields(), true)
			&& DocumentStateTable::query()->setSelect(['ID'])->where('HOST_DOC_KEY', $key)
				->where('EDIT_SCOPE_VERSION', '!=', 0)->setLimit(1)->fetch())
		{
			return true;
		}
		return in_array('PLATFORM_USER_ID', SharedEditingSchema::sessionFields(), true)
			&& (bool)PlatformSessionTable::query()->setSelect(['ID'])->where('HOST_DOC_KEY', $key)
				->whereNotNull('PLATFORM_USER_ID')->where('PLATFORM_USER_ID', '!=', '')->setLimit(1)->fetch();
	}

	/** Content and compact fences have separate persisted keyset walks. */
	private function sharedPage(int $fence, ?DateTime $threshold = null): array
	{
		$option = 'vibeoffice_shared_gc_cursor_' . $fence;
		$cursor = max(0, (int)Option::get('disk', $option, '0'));
		$query = DocumentStateTable::query()->setSelect(SharedEditingSchema::documentFields())
			->where('EDIT_SCOPE_VERSION', 1)->where('EDIT_FENCE_STATE', $fence)
			->setOrder(['ID' => 'ASC'])->setLimit(max(1, $this->batchSize));
		if ($threshold !== null) { $query->where('UPDATED_TIME', '<', $threshold); }
		$rows = (clone $query)->where('ID', '>', $cursor)->fetchAll();
		if (!$rows && $cursor > 0 && !$this->timeIsUp()) { $rows = $query->fetchAll(); }
		return [$option, $rows];
	}

	private function deleteSharedContent(DateTime $now, int $fence): bool
	{
		if (!SharedEditingUsage::hasBeenUsed() || !SharedEditingSchema::hasColumns()
			|| DocumentStateRepository::hasOpenTransaction()) { return true; }
		if (!SharedEditingSchema::hasSharedDeliveryHistoryIndexes())
		{
			self::logDiagnostic('warning', 'vibeoffice maintenance', [
				'operation' => 'delete', 'result' => 'incomplete', 'code' => 'shared_gc_history_index_missing',
			]);
			return false;
		}
		$threshold = self::getRetentionThreshold($now);
		[$option, $rows] = $this->sharedPage($fence, $threshold);
		foreach ($rows as $row)
		{
			if ($this->timeIsUp()) { return false; }
			$this->deleteSharedDocument($row, $threshold);
			Option::set('disk', $option, (string)$row['ID']);
		}
		return !$this->timeIsUp();
	}

	/** No child reads or content transactions: even a soft-deleted real object keeps its fence. */
	private function deleteCompactMarkers(): bool
	{
		if (!SharedEditingUsage::hasBeenUsed() || !SharedEditingSchema::hasColumns()) { return true; }
		[$option, $rows] = $this->sharedPage(2);
		if (!$rows || $this->timeIsUp()) { return !$this->timeIsUp(); }
		$objects = ObjectTable::query()->setSelect(['ID'])->whereIn('ID', array_unique(array_column($rows, 'OBJECT_ID')))
			->fetchAll();
		$existing = array_fill_keys(array_column($objects, 'ID'), true);
		foreach ($rows as $row)
		{
			// Finish this bounded page past the soft limit, but never start SQL past the entry reserve.
			if (!isset($existing[$row['OBJECT_ID']]) && hrtime(true) / 1e9 < $this->entryDeadline - 1.1)
			{
				$this->requireSharedMutation(DocumentStateTable::delete((int)$row['ID']));
				$this->deleted['states']++;
			}
		}
		// Persist the consumed existence page even if the query exhausted this pass's budget.
		Option::set('disk', $option, (string)end($rows)['ID']);
		return !$this->timeIsUp();
	}

	private function deleteSharedDocument(array $candidate, DateTime $threshold): void
	{
		$connection = $this->createGcLockConnection();
		$name = DocumentStateRepository::getDocumentLockName($candidate['HOST_DOC_KEY']);
		$locked = false;
		try
		{
			if ($this->timeIsUp() || !($locked = $connection->lock($name, 0))) { return; }
			$repository = new DocumentStateRepository();
			$state = null;
			$result = $repository->withPrimaryTransaction(function () use ($candidate, $threshold, &$state): Result {
				$state = $this->readSettledSharedState($candidate, $threshold);
				return new Result();
			});
			$this->requireSharedMutation($result);
			if (!$state || $this->timeIsUp()) { return; }
			// The dedicated connection keeps the document mutex while native file removal uses primary.
			foreach ($this->sharedRows($this->sharedDeliveries($state)) as $delivery)
			{
				if ((int)$delivery['STAGED_FILE_ID'] <= 0) { continue; }
				if (!$this->releaseSharedFile($state, (int)$delivery['STAGED_FILE_ID'], (int)$delivery['ID'])) { return; }
			}
			if ((int)$state['EXPECTED_FILE_ID'] > 0
				&& !$this->releaseSharedFile($state, (int)$state['EXPECTED_FILE_ID'])) { return; }
			if ($this->timeIsUp()) { return; }
			$this->requireSharedMutation($repository->withPrimaryTransaction(function () use ($state, $threshold): Result {
				$fresh = $this->readSettledSharedState($state, $threshold);
				return $fresh ? $this->deleteSharedChildren($fresh, $threshold) : new Result();
			}));
		}
		catch (\Throwable $exception)
		{
			if (!$this->timeIsUp())
			{
				$this->skipped['refusedDeletes']++;
				self::logDiagnostic('warning', 'vibeoffice maintenance', [
					'operation' => 'delete', 'result' => 'error', 'code' => 'maintenance_failed',
					'entity' => 'shared document', 'id' => (int)$candidate['ID'],
					'exception_class' => self::safeExceptionClass($exception),
				]);
			}
		}
		finally
		{
			try { if ($locked) { $connection->unlock($name); } }
			finally { $connection->disconnect(); }
		}
	}

	/** Every incomplete census fails closed; no TTL or API status can replace the terminal receipt. */
	private function readSettledSharedState(array $candidate, DateTime $threshold): ?array
	{
		$this->requireSharedBudget();
		DocumentStateRepository::fetchForUpdate(ObjectTable::query()->setSelect(['ID'])
			->where('ID', (int)$candidate['OBJECT_ID']));
		$this->requireSharedBudget();
		$state = DocumentStateRepository::fetchForUpdate(DocumentStateTable::query()
			->setSelect(SharedEditingSchema::documentFields())->where('ID', (int)$candidate['ID'])
			->where('EDIT_SCOPE_VERSION', 1)->whereIn('EDIT_FENCE_STATE', [0, 1])
			->where('UPDATED_TIME', '<', $threshold));
		if (!$state || !HostDocumentKey::matchesSharedEditScope($state, $state['HOST_DOC_KEY'])) { return null; }
		$boundaries = [];
		foreach (['HOST_DOC_KEY', 'EXTERNAL_HASH'] as $field)
		{
			$this->requireSharedBudget();
			$last = $this->fetchSharedDeliveryBoundary($state['HOST_DOC_KEY'], $field);
			$boundaries[$field] = (int)($last['ID'] ?? 0);
		}
		try { $proof = \Bitrix\Main\Web\Json::decode($state['TERMINAL_PROOF'] ?? ''); }
		catch (\Throwable) { return null; }
		$repository = new DocumentStateRepository();
		if (!is_array($proof) || !TerminalProof::matchesState($proof, $state)
			|| !$repository->matchesTerminalPlacement($state, $proof)
			|| !TerminalProof::matchesReceipt($proof, $state, $repository)) { return null; }
		foreach ($this->sharedRows(PlatformSessionTable::query()->setSelect(SharedEditingSchema::sessionFields())
			->where('HOST_DOC_KEY', $state['HOST_DOC_KEY'])) as $row)
		{
			if (!in_array($row['STATUS'], [PlatformSessionTable::STATUS_TERMINATED, PlatformSessionTable::STATUS_REFUSED], true)
				|| !in_array((int)$row['ACCESS_REVOKE_STATE'], [0, 2], true)
				|| !$row['UPDATE_TIME'] || $row['UPDATE_TIME']->getTimestamp() >= $threshold->getTimestamp()
				|| OpenDebtCarrier::filterProtectedTokens([$row])) { return null; }
		}
		foreach ($this->sharedRows($this->sharedLocals($state)) as $row)
		{
			$session = DocumentSession::buildFromArray($row);
			if (HostDocumentKey::resolve($session) !== $state['HOST_DOC_KEY']) { continue; }
			if ($session->isActive() || OpenDebtCarrier::contextProtects($row['CONTEXT'] ?? null)) { return null; }
		}
		foreach ($this->sharedRows($this->sharedDeliveries($state)) as $row)
		{
			if ($row['STATUS'] !== DeliveryTable::STATUS_DONE || !$row['PROCESSED_TIME']
				|| $row['PROCESSED_TIME']->getTimestamp() >= $threshold->getTimestamp()) { return null; }
			if ($row['EVENT'] === \Bitrix\Disk\Document\Vibeoffice\Webhook\WebhookProcessor::EVENT_SAVED)
			{
				$applied = $row['OUTCOME'] === DeliveryTable::OUTCOME_APPLIED && (int)$row['RESULT_OBJECT_ID'] > 0;
				if ((!$applied && !($this->isUnappliedOutcome($row) && $this->hasNewerAppliedSnapshot($row)))
					|| ($applied && (int)$row['NOTIFIED'] !== 1 && !DeliveryTable::isSavedNotificationSkipped($row))) { return null; }
			}
		}
		$this->requireSharedBudget();
		return $state + ['_GC_DELIVERY_BOUNDARIES' => $boundaries];
	}

	private function sharedDeliveryBoundaryQuery(string $key, string $field, ?int $after = null): Query|string
	{
		if (!in_array($field, ['HOST_DOC_KEY', 'EXTERNAL_HASH'], true) || ($after !== null && $after < 0))
		{ throw new \LogicException('Invalid shared delivery boundary.'); }
		$direction = $after === null ? 'DESC' : 'ASC';
		$connection = Application::getConnection();
		if ($connection->getType() === 'pgsql')
		{
			$helper = $connection->getSqlHelper();
			$column = $helper->quote(strtolower($field));
			$id = $helper->quote('id');
			$literal = (new SqlExpression('?', $key))->compile();
			// One tuple interval limits both index keys. Match the index's native NULL order, not the ORM's compatibility order.
			return 'SELECT ' . $id . ' FROM ' . $helper->quote(DeliveryTable::getTableName())
				. ' WHERE (' . $column . ',' . $id . ') ' . ($after === null ? '>=' : '>')
				. ' (' . $literal . ',' . ($after ?? 0) . ')'
				. ' AND (' . $column . ',' . $id . ') <= (' . $literal . ',' . PHP_INT_MAX . ')'
				. ' ORDER BY ' . $column . ' ' . $direction . ' NULLS ' . ($after === null ? 'FIRST' : 'LAST')
				. ',' . $id . ' ' . $direction . ' LIMIT 1';
		}
		if ($after === null)
		{
			$helper = $connection->getSqlHelper();
			return 'SELECT MAX(' . $helper->quote('ID') . ') AS ' . $helper->quote('ID')
				. ' FROM ' . $helper->quote(DeliveryTable::getTableName()) . ' WHERE ' . $helper->quote($field)
				. '=' . (new SqlExpression('?', $key))->compile();
		}
		$query = DeliveryTable::query()->setSelect(['ID'])->where($field, $key)
			->setOrder(['ID' => $direction])->setLimit(1);
		if ($after !== null) { $query->where('ID', '>', $after); }
		return $query;
	}

	private function fetchSharedDeliveryBoundary(string $key, string $field, ?int $after = null): ?array
	{
		if (!DocumentStateRepository::hasOpenTransaction()) { throw new \LogicException('A boundary requires its primary transaction.'); }
		$query = $this->sharedDeliveryBoundaryQuery($key, $field, $after);
		if ($query instanceof Query) { return DocumentStateRepository::fetchForUpdate($query); }
		$row = Application::getConnection()->query($query . ' FOR UPDATE')->fetch();
		return $row ? array_change_key_case($row, CASE_UPPER) : null;
	}

	/** The document mutex protects settled children; new unlocked saved preparation is fenced by indexed current reads. */
	private function isSharedGcStateCurrent(array $state): bool
	{
		$this->requireSharedBudget();
		DocumentStateRepository::fetchForUpdate(ObjectTable::query()->setSelect(['ID'])->where('ID', (int)$state['OBJECT_ID']));
		$this->requireSharedBudget();
		$fresh = DocumentStateRepository::fetchForUpdate(DocumentStateTable::query()
			->setSelect(SharedEditingSchema::documentFields())->where('ID', (int)$state['ID']));
		if (!$fresh) { return false; }
		foreach (SharedEditingSchema::documentFields() as $field)
		{
			$before = $state[$field];
			$after = $fresh[$field];
			if ($before instanceof DateTime && $after instanceof DateTime)
			{
				if ($before->getTimestamp() !== $after->getTimestamp()) { return false; }
			}
			elseif ($before !== $after) { return false; }
		}
		foreach (['HOST_DOC_KEY', 'EXTERNAL_HASH'] as $field)
		{
			$boundary = $state['_GC_DELIVERY_BOUNDARIES'][$field] ?? null;
			if (!is_int($boundary) || $boundary < 0) { return false; }
			$this->requireSharedBudget();
			if ($this->fetchSharedDeliveryBoundary($state['HOST_DOC_KEY'], $field, $boundary)) { return false; }
		}
		return true;
	}

	private function sharedLocals(array $state): Query
	{
		return DocumentSessionTable::query()->setSelect(['*'])->where('OBJECT_ID', (int)$state['OBJECT_ID'])
			->where('SERVICE', DocumentService::Vibeoffice->value)->where('TYPE', DocumentSession::TYPE_EDIT)
			->where(Query::filter()->logic('or')->whereNull('VERSION_ID')->where('VERSION_ID', 0));
	}

	private function sharedDeliveries(array $state): Query
	{
		return DeliveryTable::query()->setSelect(['*'])->where(Query::filter()->logic('or')
			->where('HOST_DOC_KEY', $state['HOST_DOC_KEY'])
			->where(Query::filter()->whereNull('HOST_DOC_KEY')->where('EXTERNAL_HASH', $state['HOST_DOC_KEY'])));
	}

	/** Bounded memory, monotonic keyset progress and a deadline check before each read and yielded row. */
	private function sharedRows(Query $query, string $field = 'ID', bool $allowPartial = false): \Generator
	{
		$cursor = 0;
		do
		{
			if ($allowPartial && $this->timeIsUp()) { return; }
			$this->requireSharedBudget();
			$page = (clone $query)->where($field, '>', $cursor)->setOrder([$field => 'ASC'])
				->setLimit(max(1, $this->batchSize));
			$rows = DocumentStateRepository::hasOpenTransaction()
				? DocumentStateRepository::fetchAllForUpdate($page) : $page->fetchAll();
			foreach ($rows as $row)
			{
				if ($allowPartial && $this->timeIsUp()) { return; }
				$this->requireSharedBudget();
				$cursor = (int)$row[$field];
				yield $row;
			}
		}
		while (count($rows) === max(1, $this->batchSize));
	}

	/** The settled state's own baseline is a retry reference, not a foreign owner. */
	private function sharedFileOwner(array $state, int $fileId): ?string
	{
		foreach ([ObjectTable::class => ['FILE_ID', 'VIEW_ID', 'PREVIEW_ID'], VersionTable::class => ['FILE_ID', 'VIEW_ID']] as $table => $fields)
		{
			foreach ($fields as $field)
			{
				$this->requireSharedBudget();
				if (DocumentStateRepository::fetchForUpdate($table::query()->setSelect(['ID'])
					->where($field, $fileId)->setLimit(1))) { return self::OWNER_DISK; }
			}
		}
		$this->requireSharedBudget();
		if (DocumentStateRepository::fetchForUpdate(DocumentStateTable::query()->setSelect(['ID'])->where('EXPECTED_FILE_ID', $fileId)
			->where('ID', '!=', (int)$state['ID'])->setLimit(1))) { return self::OWNER_VIBEOFFICE; }
		$this->requireSharedBudget();
		if (DocumentStateRepository::fetchForUpdate(DeliveryTable::query()->setSelect(['ID'])->where('STAGED_FILE_ID', $fileId)
			->where(Query::filter()->logic('or')->whereNull('HOST_DOC_KEY')->where('HOST_DOC_KEY', '!=', $state['HOST_DOC_KEY']))
			->setLimit(1))) { return self::OWNER_VIBEOFFICE; }
		return null;
	}

	private function releaseSharedFile(array $state, int $fileId, ?int $deliveryId = null): bool
	{
		$this->requireSharedBudget();
		$primary = Application::getConnection();
		if (!$primary->lock('b_file', 0)) { return false; }
		$nativeStarted = false;
		try
		{
			$repository = new DocumentStateRepository();
			$owner = null;
			$ready = false;
			$this->requireSharedMutation($repository->withPrimaryTransaction(function () use ($state, $fileId, &$owner, &$ready): Result {
				$ready = $this->isSharedGcStateCurrent($state);
				if ($ready) { $owner = $this->sharedFileOwner($state, $fileId); }
				return new Result();
			}));
			if (!$ready || $owner === self::OWNER_VIBEOFFICE || $this->timeIsUp()) { return false; }
			if ($owner !== self::OWNER_DISK && $primary->queryScalar('SELECT ID FROM b_file WHERE ID=' . $fileId))
			{
				$this->requireSharedBudget();
				$nativeStarted = true;
				$removed = $this->fileRemover->remove($fileId);
				$nativeStarted = false;
				if (!$removed) { return false; }
				$this->requireSharedBudget();
				// CFile::Delete returns void and may refuse a duplicate. Never use its wrapper's bool as proof.
				if ($primary->queryScalar('SELECT ID FROM b_file WHERE ID=' . $fileId))
				{
					$this->skipped['refusedDeletes']++;
					self::logDiagnostic('warning', 'vibeoffice maintenance', [
						'operation' => 'delete', 'result' => 'error', 'code' => 'maintenance_file_retained',
					]);
					return false;
				}
				$this->deleted['files']++;
			}
			$this->requireSharedBudget();
			$this->requireSharedMutation($repository->withPrimaryTransaction(function () use ($state, $fileId, $deliveryId, &$ready): Result {
				$ready = $this->isSharedGcStateCurrent($state);
				if (!$ready || $deliveryId === null) { return new Result(); }
				$row = DocumentStateRepository::fetchForUpdate(DeliveryTable::query()->setSelect(['ID', 'HOST_DOC_KEY', 'EXTERNAL_HASH', 'STAGED_FILE_ID'])
					->where('ID', $deliveryId));
				if ($row && ($row['HOST_DOC_KEY'] === $state['HOST_DOC_KEY']
					|| ($row['HOST_DOC_KEY'] === null && $row['EXTERNAL_HASH'] === $state['HOST_DOC_KEY']))
					&& (int)$row['STAGED_FILE_ID'] === $fileId)
				{
					$this->requireSharedMutation(DeliveryTable::update((int)$row['ID'], ['STAGED_FILE_ID' => null]));
					$this->deleted['detached']++;
				}
				return new Result();
			}));
			return $ready;
		}
		finally
		{
			// PostgreSQL's native Delete can throw after its recursive acquisition and before its unlock.
			if ($nativeStarted && $primary->getType() === 'pgsql') { $primary->unlock('b_file'); }
			$primary->unlock('b_file');
		}
	}

	/** Partial cleanup keeps the exact placement and receipt until the final atomic state change. */
	private function deleteSharedChildren(array $state, DateTime $threshold): Result
	{
		$key = $state['HOST_DOC_KEY'];
		$proof = \Bitrix\Main\Web\Json::decode($state['TERMINAL_PROOF']);
		$anchor = PlatformSessionTable::query()->setSelect(['ID'])->where('HOST_DOC_KEY', $key)
			->whereNotNull('EDITOR_KEY')->whereNotNull('REV')->whereNotNull('PLATFORM_USER_ID')
			->whereNotNull('OPEN_TOKEN')->whereNotNull('API_SESSION_ID')->where('DOCUMENT_SESSION_ID', '>', 0)
			->setOrder(['ID' => 'DESC'])->setLimit(1)->fetch();
		if (!$anchor) { return new Result(); }
		$receiptSeq = (int)($proof['receipt']['SNAPSHOT_SEQ'] ?? 0);
		$queries = [
			[DocumentSessionTable::class, $this->sharedLocals($state), 'ID'],
			[DeliveryTable::class, $this->sharedDeliveries($state), 'ID'],
			[SavedSnapshotTable::class, SavedSnapshotTable::query()->setSelect(['HOST_DOC_KEY', 'SNAPSHOT_SEQ'])
				->where('HOST_DOC_KEY', $key)->where('SNAPSHOT_SEQ', '!=', $receiptSeq), 'SNAPSHOT_SEQ'],
			[PlatformSessionTable::class, PlatformSessionTable::query()->setSelect(['ID'])
				->where('HOST_DOC_KEY', $key)->where('ID', '!=', (int)$anchor['ID']), 'ID'],
		];
		foreach ($queries as [$table, $query, $field])
		{
			foreach ($this->sharedRows($query, $field, allowPartial: true) as $row)
			{
				if ($table === DocumentSessionTable::class
					&& HostDocumentKey::resolve(DocumentSession::buildFromArray($row)) !== $key) { continue; }
				if ($table === DeliveryTable::class)
				{
					$boundary = $state['_GC_DELIVERY_BOUNDARIES'][$row['HOST_DOC_KEY'] === null ? 'EXTERNAL_HASH' : 'HOST_DOC_KEY'] ?? null;
					if ($row['STATUS'] !== DeliveryTable::STATUS_DONE || !$row['PROCESSED_TIME']
						|| $row['PROCESSED_TIME']->getTimestamp() >= $threshold->getTimestamp()
						|| (int)$row['STAGED_FILE_ID'] > 0 || !is_int($boundary) || $boundary < 0
						|| (int)$row['ID'] > $boundary) { return new Result(); }
				}
				$primary = $table === SavedSnapshotTable::class ? ['HOST_DOC_KEY' => $key, 'SNAPSHOT_SEQ' => (int)$row[$field]] : (int)$row[$field];
				$this->requireSharedMutation($table::delete($primary));
				if ($table === DocumentSessionTable::class
					&& DocumentSessionTable::query()->setSelect(['ID'])->where('ID', $primary)->fetch()) { return new Result(); }
				// Commit completed pages with their proof anchors intact instead of rolling them back on timeout.
				if ($this->timeIsUp()) { return new Result(); }
			}
			if ($this->timeIsUp()) { return new Result(); }
		}
		if ($this->timeIsUp()) { return new Result(); }
		if (!$this->isSharedGcStateCurrent($state)) { return new Result(); }
		if ($receiptSeq > 0) { $this->requireSharedMutation(SavedSnapshotTable::delete(['HOST_DOC_KEY' => $key, 'SNAPSHOT_SEQ' => $receiptSeq])); }
		$this->requireSharedBudget();
		$this->requireSharedMutation(PlatformSessionTable::delete((int)$anchor['ID']));
		$this->requireSharedBudget();
		$this->requireSharedMutation((int)$state['EDIT_FENCE_STATE'] === 0
			? DocumentStateTable::delete((int)$state['ID'])
			: DocumentStateTable::update((int)$state['ID'], [
				'EDIT_FENCE_STATE' => 2, 'EXPECTED_FILE_ID' => 0, 'EXPECTED_CONTENT_VERSION' => 0,
				'EXPECTED_ETAG' => '', 'TERMINAL_PROOF' => null,
			]));
		return new Result();
	}

	private function requireSharedBudget(): void
	{
		if ($this->timeIsUp()) { throw new \RuntimeException('Shared content cleanup budget expired.'); }
	}

	private function requireSharedMutation(Result $result): void
	{
		if (!$result->isSuccess()) { throw new \RuntimeException('Shared content cleanup was refused.'); }
	}

	private function timeIsUp(): bool
	{
		// Do not start another one-second SQL wait too close to the shared entry deadline.
		return hrtime(true) / 1e9 >= min($this->deadline, $this->entryDeadline - 1.1);
	}

	/**
	 * Every row obeys the shared entry deadline, including the first row of a new category.
	 */
	private function batchIsOver(int $processedRows): bool
	{
		return $this->timeIsUp();
	}

	private function report(string $mode, bool $busy, bool $complete, string $stopReason): Result
	{
		[$outcome, $code] = match ($stopReason)
		{
			'time_limit' => ['incomplete', 'maintenance_time_limit'],
			'busy' => ['busy', 'maintenance_busy'],
			'exception' => ['error', 'maintenance_failed'],
			default => ['ok', null],
		};
		$stopCategory = null;
		if ($stopReason === 'exception')
		{
			$stopCategory = $this->currentCategory;
		}
		elseif ($stopReason === 'time_limit')
		{
			foreach ($this->categories as $name => $categoryComplete)
			{
				if (!$categoryComplete)
				{
					$stopCategory = $name;
				}
			}
		}
		$data = [
			'mode' => $mode,
			'busy' => $busy,
			'deleted' => $this->deleted,
			'skipped' => $this->skipped,
			'categories' => $this->categories,
			'complete' => $complete,
			'elapsedSeconds' => round(hrtime(true) / 1e9 - $this->startedAt, 3),
			'sharedAccess' => $this->sharedAccess,
		];
		$context = [
			'operation' => 'pass',
			'result' => $outcome,
			'code' => $code,
			'stop_reason' => $stopReason,
			'stop_category' => $stopCategory,
			'mode' => $mode,
			'busy' => $busy,
			'complete' => $complete,
			'elapsedSeconds' => $data['elapsedSeconds'],
		];
		foreach (['deleted', 'skipped', 'categories'] as $group)
		{
			foreach ($data[$group] as $name => $value)
			{
				$context[$group . '_' . $name] = $value;
			}
		}
		self::logDiagnostic($stopReason === 'exception' ? 'warning' : 'info', 'vibeoffice maintenance', $context);

		return (new Result())->setData($data);
	}

	public static function logger(): ?LoggerInterface
	{
		try
		{
			return LoggerProvider::get(self::LOGGER_ID);
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	private static function logDiagnostic(string $level, string $message, array $context = []): void
	{
		try
		{
			self::logger()?->log($level, $message, $context);
		}
		catch (\Throwable)
		{
			// Diagnostics must not affect the cleanup result.
		}
	}

	private static function safeExceptionClass(\Throwable $exception): string
	{
		$class = $exception::class;

		return preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]{0,127}$/D', $class) === 1
			? $class
			: 'unknown';
	}
}

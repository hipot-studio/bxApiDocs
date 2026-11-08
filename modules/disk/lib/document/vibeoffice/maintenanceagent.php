<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\DocumentSessionTable;
use Bitrix\Disk\Document\Vibeoffice\Models\DocumentStateTable;
use Bitrix\Disk\Document\Vibeoffice\Models\PlatformSessionTable;
use Bitrix\Disk\Document\Vibeoffice\Models\SavedSnapshotTable;
use Bitrix\Disk\Document\Vibeoffice\Webhook\DeliveryTable;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Internals\VersionTable;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Diag\LoggerFactory;
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
 * their files, live sessions, document states and saved snapshots are never selected.
 */
final class MaintenanceAgent
{
	public const LOCK_NAME = 'disk_vo_maintenance';
	public const BATCH_SIZE = 500;
	public const SOFT_TIME_LIMIT_SECONDS = 20;
	public const PENDING_TTL_SECONDS = 86400;
	public const RETENTION_DAYS = 30;
	/** A delivery that has just finished is left alone: its own writes may not have reached a replica yet. */
	public const STAGED_FILE_MIN_AGE_SECONDS = 300;
	public const OPTION_MODE = 'vibeoffice_maintenance_mode';

	public const MODE_OFF = 'off';
	public const MODE_APPLY = 'apply';

	/** Reachable from an object or a version: Disk has accepted the blob, the delivery no longer owns it. */
	private const OWNER_DISK = 'disk';
	/** Still pinned by the editing subsystem itself: another delivery or an expected content of a document. */
	private const OWNER_VIBEOFFICE = 'vibeoffice';

	private const LOGGER_ID = 'disk.vibeoffice.maintenance';
	private const SECONDS_PER_DAY = 86400;

	private float $startedAt = 0.0;
	private float $deadline = 0.0;
	private array $deleted = [];
	private array $skipped = [];
	private array $categories = [];
	private array $countedOpenFileIds = [];

	public function __construct(
		private readonly int $batchSize = self::BATCH_SIZE,
		private readonly float $softTimeLimitSeconds = self::SOFT_TIME_LIMIT_SECONDS,
		private readonly FileRemoverInterface $fileRemover = new FileRemover(),
	)
	{
	}

	public function run(?DateTime $now = null): Result
	{
		$this->startedAt = microtime(true);
		$this->deadline = $this->startedAt;
		$this->deleted = ['pending' => 0, 'terminated' => 0, 'deliveries' => 0, 'files' => 0, 'detached' => 0];
		$this->skipped = [
			'busyDocuments' => 0,
			'referenced' => 0,
			'openTransaction' => 0,
			'missingDocumentKey' => 0,
			'invalidStagedFile' => 0,
			'refusedDeletes' => 0,
			'openObligations' => 0,
			'openObligationBytes' => 0,
		];
		$this->categories = [];
		$this->countedOpenFileIds = [];

		$mode = self::readMode();
		if ($mode !== self::MODE_APPLY)
		{
			return $this->report($mode, false, true);
		}

		// A replica may still miss the save that accepted a blob, so the whole pass reads from the master.
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			return $this->runUnderLock($mode, $now ?? new DateTime());
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}

	private function runUnderLock(string $mode, DateTime $now): Result
	{
		$connection = Application::getConnection();
		if (!$connection->lock(self::LOCK_NAME, 0))
		{
			return $this->report($mode, true, false);
		}

		$failure = null;
		try
		{
			$complete = $this->runCategories($now);
		}
		catch (\Throwable $exception)
		{
			$complete = false;
			$failure = new Error($exception->getMessage(), 'maintenance_failed');
			self::logger()?->error('Vibeoffice maintenance failed: {exception}: {message}.', [
				'exception' => $exception::class,
				'message' => $exception->getMessage(),
			]);
		}
		finally
		{
			$connection->unlock(self::LOCK_NAME);
		}

		$result = $this->report($mode, false, $complete);

		return $failure ? $result->addError($failure) : $result;
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
			self::logger()?->info('Unknown vibeoffice maintenance mode {mode} is treated as off.', ['mode' => $mode]);
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
		$categories = [
			'pending' => fn(): bool => $this->deleteExpiredPending($now),
			'terminated' => fn(): bool => $this->deleteExpiredTerminated($now),
			'stagedFiles' => fn(): bool => $this->releaseStagedFiles($now),
			'deliveries' => fn(): bool => $this->deleteExpiredDeliveries($now),
		];
		$share = $this->softTimeLimitSeconds / count($categories);
		$budget = 0.0;
		$complete = true;
		foreach ($categories as $name => $category)
		{
			$budget += $share;
			$this->deadline = $this->startedAt + $budget;
			$this->categories[$name] = $category();
			$complete = $complete && $this->categories[$name];
		}

		return $complete;
	}

	private function deleteExpiredPending(DateTime $now): bool
	{
		return $this->deleteSessions(
			PlatformSessionTable::STATUS_PENDING,
			'CREATE_TIME',
			self::getPendingThreshold($now),
			'pending',
			true,
		);
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
	): bool
	{
		$lastId = 0;
		while (true)
		{
			$rows = PlatformSessionTable::query()
				->setSelect(['ID', 'DOCUMENT_SESSION_ID'])
				->where('STATUS', $status)
				->where($timeField, '<', $threshold)
				->where('ID', '>', $lastId)
				->setOrder(['ID' => 'ASC'])
				->setLimit($this->batchSize)
				->fetchAll()
			;
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
			$rows = DeliveryTable::query()
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
				->fetchAll()
			;
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
		if (!$fresh || $fresh['STATUS'] !== DeliveryTable::STATUS_DONE
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
			self::logger()?->error(
				'Vibeoffice maintenance kept the staged file {file} of delivery {delivery}: removal refused.',
				['file' => $fileId, 'delivery' => $ledgerId],
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
		self::logger()?->error('Vibeoffice maintenance could not delete the {entity} {id}: {errors}.', [
			'entity' => $entity,
			'id' => $id,
			'errors' => implode('; ', $result->getErrorMessages()),
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
		self::logger()?->error('Vibeoffice maintenance could not clear the stage of delivery {delivery}: {errors}.', [
			'delivery' => $ledgerId,
			'errors' => implode('; ', $result->getErrorMessages()),
		]);

		return false;
	}

	private function deleteExpiredDeliveries(DateTime $now): bool
	{
		$threshold = self::getRetentionThreshold($now);
		$lastId = 0;
		while (true)
		{
			$rows = DeliveryTable::query()
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
				->fetchAll()
			;
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
							$deleted = $fresh && $fresh['STATUS'] === DeliveryTable::STATUS_DONE
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
			->fetch();
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

	private function timeIsUp(): bool
	{
		return microtime(true) >= $this->deadline;
	}

	/**
	 * The first row of a batch always runs, so every category moves forward; the rows behind it obey the
	 * deadline, and what a batch did not reach is picked up by the next pass from the same threshold.
	 */
	private function batchIsOver(int $processedRows): bool
	{
		return $processedRows > 0 && $this->timeIsUp();
	}

	private function report(string $mode, bool $busy, bool $complete): Result
	{
		return (new Result())->setData([
			'mode' => $mode,
			'busy' => $busy,
			'deleted' => $this->deleted,
			'skipped' => $this->skipped,
			'categories' => $this->categories,
			'complete' => $complete,
			'elapsedSeconds' => round(microtime(true) - $this->startedAt, 3),
		]);
	}

	public static function logger(): ?LoggerInterface
	{
		return (new LoggerFactory())->createById(self::LOGGER_ID, isCheckEnabledFromRegistry: false);
	}
}

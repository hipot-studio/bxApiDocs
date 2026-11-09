<?php

namespace Bitrix\Landing\Markup;

use Bitrix\Landing\Connector;
use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\Scenario\ChangeMarkupSite;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateMarkupSite;
use Bitrix\Landing\Copilot\Generation\Scenario\MarkupSiteState;
use Bitrix\Landing\Copilot\Model\GenerationsTable;
use Bitrix\Landing\File;
use Bitrix\Landing\Internals\MarkupUploadTable;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Security\Random;
use Bitrix\Main\Type\DateTime;

/**
 * Buffer of an unfinished markup package upload (DTO-03): the transport between the
 * per-file tool calls and the build scenario. The buffer belongs to its user and to
 * one target (a chat binding for create, a page for update); a foreign uploadId is
 * never served. File bytes are stored under random neutral names (nothing from the
 * buffer is ever served as a document) with regular bindings of the buffer row.
 *
 * The buffer lives TTL_SECONDS since its last activity (DATE_MODIFY): filling, submission,
 * generation start, cancel and the terminal outcome extend it through touch(). A buffer
 * a running generation refers to never expires; the rest is dropped by clearExpired().
 *
 * A file arrives whole (putFile), in base64 parts glued by finishFile() or from a Disk object
 * (putDiskFile). Parts are stored as separate files of the buffer row until the file is finished;
 * a part that does not continue the file drops all accepted parts of its path. Part files are deleted
 * at once when they are glued, dropped or expire; whole files go through the deferred deletion of landing
 * files, since a generation may have bound them to its blocks.
 *
 * Every write checks the portal disk quota. A user keeps at most MAX_BUFFERS_PER_USER buffers: opening
 * one more drops the longest idle buffer no running generation refers to.
 */
class UploadBuffer
{
	public const MODE_CREATE = 'create';
	public const MODE_UPDATE = 'update';
	// the dev entry creates a site without a chat: its buffer has no target
	public const MODE_DEV = 'dev';

	public const ENCODING_TEXT = 'text';
	public const ENCODING_BASE64 = 'base64';

	public const TTL_SECONDS = 7200;

	public const ERROR_UPLOAD_EXPIRED = 'MARKUP_UPLOAD_EXPIRED';
	public const ERROR_UPLOAD_NOT_FOUND = 'MARKUP_UPLOAD_NOT_FOUND';
	public const ERROR_CHUNK_MISMATCH = 'MARKUP_CHUNK_MISMATCH';
	public const ERROR_BASE64_INVALID = 'MARKUP_BASE64_INVALID';
	public const ERROR_DISK_FILE_DENIED = 'MARKUP_DISK_FILE_DENIED';
	public const ERROR_DISK_QUOTA = FileStore::ERROR_DISK_QUOTA;

	public const MAX_BUFFERS_PER_USER = 10;

	public const CHUNK_BYTES_OPTION = 'landing_markup_chunk_bytes';
	public const DEFAULT_CHUNK_BYTES = 14000;
	public const WRITE_CHUNK_BYTES_OPTION = 'landing_markup_write_chunk_bytes';
	public const DEFAULT_WRITE_CHUNK_BYTES = 100000;

	private const ROW_SELECT = [
		'ID', 'UID', 'CREATED_BY_ID', 'TARGET_MODE', 'TARGET_ID', 'FILES', 'DATE_CREATE', 'DATE_MODIFY',
	];
	private const CLEANUP_BATCH_SIZE = 100;
	// the cleanup moves past buffers held by running generations, at most this many pages per run
	private const CLEANUP_MAX_PAGES = 10;
	// part files deleted per cleanup run; the rest waits for the next run
	private const CLEANUP_PARTS_BUDGET = 2000;
	private const USED_BY_GENERATION_LOOKUP_LIMIT = 5;
	private const ROW_LOCK_TIMEOUT_SECONDS = 10;
	private const MARKUP_SCENARIOS = [CreateMarkupSite::class, ChangeMarkupSite::class];

	/**
	 * Opens a new buffer for the target, returns the buffer record.
	 */
	public function open(int $userId, string $mode, int $targetId): ?array
	{
		if (
			$userId <= 0
			|| !in_array($mode, [self::MODE_CREATE, self::MODE_UPDATE, self::MODE_DEV], true)
			|| ($mode === self::MODE_DEV ? $targetId !== 0 : $targetId <= 0)
		)
		{
			return null;
		}

		if (!$this->makeRoomForBuffer($userId))
		{
			return null;
		}

		$now = $this->now();
		$result = MarkupUploadTable::add([
			'UID' => Random::getString(32),
			'CREATED_BY_ID' => $userId,
			'TARGET_MODE' => $mode,
			'TARGET_ID' => $targetId,
			'FILES' => [],
			'DATE_CREATE' => $now,
			'DATE_MODIFY' => clone $now,
		]);
		if (!$result->isSuccess())
		{
			return null;
		}

		return $this->getByRowId((int)$result->getId(), $userId);
	}

	/**
	 * Returns the buffer record of the user, null for a foreign, unknown or expired uploadId.
	 */
	public function get(string $uploadId, int $userId): ?array
	{
		$found = $this->find($uploadId, $userId);

		return $found->isSuccess() ? $found->getData()['record'] : null;
	}

	/**
	 * Looks the buffer of the user up. Success data: ['record' => array]. A foreign or unknown
	 * uploadId answers ERROR_UPLOAD_NOT_FOUND; an own buffer idle longer than its lifetime answers
	 * ERROR_UPLOAD_EXPIRED with the buffer target in the error data - the caller still checks
	 * its rights on the target before it answers.
	 */
	public function find(string $uploadId, int $userId): Result
	{
		$result = new Result();

		$row = $this->fetchOwnRow($uploadId, $userId);
		if (!$row)
		{
			return $result->addError(new Error(
				'Upload buffer was not found or belongs to another user.',
				self::ERROR_UPLOAD_NOT_FOUND,
			));
		}

		$record = $this->normalizeRow($row);
		if ($this->isExpired($row))
		{
			return $result->addError(new Error(
				'The upload buffer expired after ' . intdiv(self::TTL_SECONDS, 3600) . ' hours without activity.'
				. ' Open a new buffer and upload the package again.',
				self::ERROR_UPLOAD_EXPIRED,
				['target' => $record['target']],
			));
		}

		return $result->setData(['record' => $record]);
	}

	/**
	 * Restarts the buffer lifetime.
	 */
	public function touch(string $uploadId): void
	{
		if ($uploadId === '')
		{
			return;
		}

		$row = MarkupUploadTable::query()
			->setSelect(['ID'])
			->where('UID', '=', $uploadId)
			->fetch()
		;
		if ($row)
		{
			MarkupUploadTable::update($row['ID'], ['DATE_MODIFY' => $this->now()]);
		}
	}

	/**
	 * Stores a file into the buffer. A repeated path replaces the file; unchanged
	 * bytes are not re-stored, so a continuation after a break is cheap.
	 * @return array{path: string, fileId: int, hash: string, size: int, encoding: string}|null
	 */
	public function putFile(
		string $uploadId,
		int $userId,
		string $path,
		string $content,
		string $encoding = self::ENCODING_TEXT
	): ?array
	{
		return $this->runLocked($uploadId, $userId, function() use ($uploadId, $userId, $path, $content, $encoding)
		{
			$record = $this->get($uploadId, $userId);
			if (!$record)
			{
				return null;
			}

			$stored = $this->storeFile($record, $path, $content, $encoding);

			return $stored->isSuccess() ? $stored->getData() : null;
		});
	}

	/**
	 * Accepts the next base64 part of a file. The offset must equal the bytes accepted so far;
	 * a repeated part (the same offset and bytes) changes nothing. Every refusal but an unknown
	 * or expired buffer drops the accepted parts of the path: the file is passed again from the start.
	 * @return Result Success data: ['path' => string, 'received' => int].
	 */
	public function putFilePart(string $uploadId, int $userId, string $path, int $offset, string $content): Result
	{
		return $this->runLocked(
			$uploadId,
			$userId,
			fn(): Result => $this->acceptPart($uploadId, $userId, $path, $offset, $content),
		);
	}

	private function acceptPart(string $uploadId, int $userId, string $path, int $offset, string $content): Result
	{
		$found = $this->find($uploadId, $userId);
		if (!$found->isSuccess())
		{
			return $found;
		}
		$record = $found->getData()['record'];
		$parts = $record['parts'][$path] ?? [];
		$received = array_sum(array_column($parts, 'size'));

		$writeChunkLimit = $this->getWriteChunkLimit();
		if (strlen($content) > $writeChunkLimit)
		{
			return $this->refusePart($record, $path, new Error(
				'The part exceeds ' . $writeChunkLimit . ' base64 characters.',
				self::ERROR_CHUNK_MISMATCH,
			));
		}

		$bytes = base64_decode($content, true);
		if ($bytes === false)
		{
			return $this->refusePart($record, $path, new Error(
				'The part is not valid base64.',
				self::ERROR_BASE64_INVALID,
			));
		}

		$hash = FileStore::hash($bytes);
		foreach ($parts as $part)
		{
			if ($part['offset'] !== $offset)
			{
				continue;
			}
			if ($part['hash'] !== $hash)
			{
				return $this->refusePart($record, $path, new Error(
					'Another part was already accepted at offset ' . $offset . '.',
					self::ERROR_CHUNK_MISMATCH,
				));
			}
			$this->touchRow($record['id']);

			return (new Result())->setData(['path' => $path, 'received' => $received]);
		}

		if ($bytes === '' || $offset !== $received)
		{
			return $this->refusePart($record, $path, new Error(
				'The part must continue the file at offset ' . $received . '.',
				self::ERROR_CHUNK_MISMATCH,
			));
		}

		$sizeError = $this->checkFill($record, $path, $received + strlen($bytes));
		if ($sizeError !== null)
		{
			return $this->refusePart($record, $path, $sizeError);
		}

		if (!$this->hasDiskQuotaFor(strlen($bytes)))
		{
			return $this->refusePart($record, $path, $this->createQuotaError($path));
		}

		$fileId = $this->saveBytes($bytes);
		if ($fileId <= 0)
		{
			return $this->refusePart($record, $path, new Error(
				'The part was not stored. Pass the file again from the start.',
				self::ERROR_CHUNK_MISMATCH,
			));
		}
		File::addToMarkupUpload($record['id'], $fileId);

		$accepted = $record['parts'];
		$accepted[$path][] = ['offset' => $offset, 'fileId' => $fileId, 'size' => strlen($bytes), 'hash' => $hash];
		if (!$this->saveRow($record['id'], $record['files'], $accepted))
		{
			File::deleteMarkupUploadParts($record['id'], [$fileId]);

			return $this->refusePart($record, $path, new Error(
				'The part was not stored. Pass the file again from the start.',
				self::ERROR_CHUNK_MISMATCH,
			));
		}

		return (new Result())->setData(['path' => $path, 'received' => $received + strlen($bytes)]);
	}

	/**
	 * Glues the accepted parts of the file and checks them against the sha256 of the whole file.
	 * A repeated finish with the same sha256 answers the same file. The parts are read before the
	 * buffer is locked - they never change once accepted - so parallel calls do not wait for the reading.
	 * @return Result Success data: ['path' => string, 'fileId' => int, 'hash' => string, 'size' => int].
	 */
	public function finishFile(string $uploadId, int $userId, string $path, string $sha256): Result
	{
		$sha256 = mb_strtolower(trim($sha256));
		$snapshot = $this->find($uploadId, $userId);
		$preRead = $snapshot->isSuccess()
			? $this->readParts($snapshot->getData()['record']['parts'][$path] ?? [])
			: null
		;

		return $this->runLocked(
			$uploadId,
			$userId,
			fn(): Result => $this->glueParts($uploadId, $userId, $path, $sha256, $preRead),
		);
	}

	/**
	 * @param array{fileIds: list<int>, content: string}|null $preRead Parts read before the lock.
	 */
	private function glueParts(string $uploadId, int $userId, string $path, string $sha256, ?array $preRead): Result
	{
		$found = $this->find($uploadId, $userId);
		if (!$found->isSuccess())
		{
			return $found;
		}
		$record = $found->getData()['record'];

		$parts = $record['parts'][$path] ?? [];
		if (!$parts)
		{
			foreach ($record['files'] as $file)
			{
				if ($file['path'] === $path && $file['hash'] === $sha256)
				{
					$this->touchRow($record['id']);

					return (new Result())->setData($this->describeFile($file));
				}
			}

			return (new Result())->addError(new Error(
				'No accepted parts of the file to finish.',
				self::ERROR_CHUNK_MISMATCH,
			));
		}

		$glued = ($preRead !== null && $preRead['fileIds'] === $this->sortParts($parts)['fileIds'])
			? $preRead
			: $this->readParts($parts)
		;
		if ($glued === null)
		{
			return $this->refusePart($record, $path, new Error(
				'An accepted part is no longer available.',
				self::ERROR_CHUNK_MISMATCH,
			));
		}

		if (FileStore::hash($glued['content']) !== $sha256)
		{
			return $this->refusePart($record, $path, new Error(
				'The glued file does not match its sha256.',
				self::ERROR_CHUNK_MISMATCH,
			));
		}

		return $this->acceptFile(
			$record,
			$path,
			$glued['content'],
			fn(Error $error): Result => $this->refusePart($record, $path, $error),
		);
	}

	/**
	 * Reads the parts in the order of their offsets.
	 * @return array{fileIds: list<int>, content: string}|null Null when a part is missing.
	 */
	private function readParts(array $parts): ?array
	{
		if (!$parts)
		{
			return null;
		}

		$sorted = $this->sortParts($parts);
		$content = '';
		foreach ($sorted['fileIds'] as $fileId)
		{
			$bytes = FileStore::readContent($fileId);
			if ($bytes === null)
			{
				return null;
			}
			$content .= $bytes;
		}

		return ['fileIds' => $sorted['fileIds'], 'content' => $content];
	}

	/**
	 * @return array{fileIds: list<int>}
	 */
	private function sortParts(array $parts): array
	{
		usort($parts, static fn(array $a, array $b): int => $a['offset'] <=> $b['offset']);

		return ['fileIds' => array_map(static fn(array $part): int => (int)$part['fileId'], $parts)];
	}

	/**
	 * Copies a Disk file of the user into the buffer. A missing, foreign, trashed object, a folder
	 * and an absent disk module answer ERROR_DISK_FILE_DENIED; the bytes then pass the same path,
	 * type and size checks as a file passed by content.
	 * @return Result Success data: ['path' => string, 'fileId' => int, 'hash' => string, 'size' => int].
	 */
	public function putDiskFile(string $uploadId, int $userId, string $path, int $diskFileId): Result
	{
		return $this->runLocked(
			$uploadId,
			$userId,
			fn(): Result => $this->copyDiskFile($uploadId, $userId, $path, $diskFileId),
		);
	}

	private function copyDiskFile(string $uploadId, int $userId, string $path, int $diskFileId): Result
	{
		$found = $this->find($uploadId, $userId);
		if (!$found->isSuccess())
		{
			return $found;
		}
		$record = $found->getData()['record'];

		$read = $this->readDiskFile($diskFileId, $userId);
		if (!$read->isSuccess())
		{
			$tooBig = $read->getErrorCollection()->getErrorByCode(Connector\Disk::ERROR_FILE_TOO_BIG) !== null;

			return (new Result())->addError($tooBig
				? new Error('The file exceeds the single file size limit.', 'MARKUP_FILE_TOO_BIG')
				: new Error('The disk file is not available to the user.', self::ERROR_DISK_FILE_DENIED)
			);
		}

		return $this->acceptFile(
			$record,
			$path,
			(string)$read->getData()['content'],
			static fn(Error $error): Result => (new Result())->addError($error),
		);
	}

	/**
	 * Max length of a part read from a stored package, in base64 characters.
	 */
	public function getChunkLimit(): int
	{
		$limit = (int)Option::get('landing', self::CHUNK_BYTES_OPTION, (string)self::DEFAULT_CHUNK_BYTES);

		return $limit > 0 ? $limit : self::DEFAULT_CHUNK_BYTES;
	}

	/**
	 * Max length of a part written to the buffer, in base64 characters.
	 */
	public function getWriteChunkLimit(): int
	{
		$limit = (int)Option::get('landing', self::WRITE_CHUNK_BYTES_OPTION, (string)self::DEFAULT_WRITE_CHUNK_BYTES);

		return $limit > 0 ? $limit : self::DEFAULT_WRITE_CHUNK_BYTES;
	}

	/**
	 * The file size against the single file limit and the package limits of the buffer, where
	 * unfinished files of other paths count with the bytes accepted so far. A pure check of the
	 * record: callers run it before anything is written.
	 */
	public function checkFill(?array $record, string $path, int $size): ?Error
	{
		if ($size > Validator::LIMIT_FILE_SIZE)
		{
			return new Error('The file exceeds the single file size limit.', 'MARKUP_FILE_TOO_BIG');
		}

		$files = $record['files'] ?? [];
		$filePaths = array_column($files, 'path');
		foreach (($record['parts'] ?? []) as $partPath => $parts)
		{
			if ($partPath !== $path && !in_array($partPath, $filePaths, true))
			{
				$files[] = ['path' => (string)$partPath, 'size' => array_sum(array_column($parts, 'size'))];
			}
		}

		return $this->getValidator()->validateBufferFill($files, $path, $size);
	}

	/**
	 * Whether the portal disk quota takes that many more bytes.
	 */
	public function hasDiskQuotaFor(int $bytes): bool
	{
		return (new \CDiskQuota())->checkDiskQuota(['size' => $bytes]) !== false;
	}

	/**
	 * Drops the buffer, expired or not: releases its files and removes the registry row.
	 */
	public function drop(string $uploadId, int $userId): bool
	{
		$row = $this->fetchOwnRow($uploadId, $userId);
		if (!$row)
		{
			return false;
		}

		$this->releaseRow((int)$row['ID'], $row['FILES'] ?? []);

		return true;
	}

	/**
	 * Drops the buffers idle longer than their lifetime, except the ones a running generation
	 * refers to: releases their files and removes the registry rows. Called by the cleanup agent.
	 * The rows are walked by a keyset over ID, so held buffers never block the ones behind them.
	 *
	 * @return int Number of dropped buffers.
	 */
	public function clearExpired(): int
	{
		$threshold = $this->getExpiryThreshold();
		$batchSize = $this->getCleanupBatchSize();
		$unfinished = null;
		$dropped = 0;
		$releasedParts = 0;
		$lastId = 0;

		for ($page = 0; $page < self::CLEANUP_MAX_PAGES; $page++)
		{
			$rows = MarkupUploadTable::query()
				->setSelect(['ID', 'UID', 'CREATED_BY_ID', 'DATE_CREATE'])
				->where('DATE_MODIFY', '<', $threshold)
				->where('ID', '>', $lastId)
				->setOrder(['ID' => 'ASC'])
				->setLimit($batchSize)
				->fetchAll()
			;

			foreach ($rows as $row)
			{
				$lastId = (int)$row['ID'];
				$unfinished ??= $this->loadUnfinishedGenerations();
				if ($this->isHeldByLiveGeneration($row, $unfinished))
				{
					continue;
				}

				$released = $this->releaseExpiredRow($lastId, $threshold);
				if ($released === null)
				{
					continue;
				}
				$releasedParts += $released;
				$dropped++;
				if ($dropped >= $batchSize || $releasedParts >= self::CLEANUP_PARTS_BUDGET)
				{
					return $dropped;
				}
			}

			if (count($rows) < $batchSize)
			{
				break;
			}
		}

		return $dropped;
	}

	/**
	 * Whether a markup generation of the user was fed from the buffer: the buffer of such an uploadId
	 * existed and was dropped after its generation, it is not a foreign or a made-up identifier.
	 */
	public function wasUsedByGenerationOf(string $uploadId, int $userId): bool
	{
		return $this->findUsedByGenerationId($uploadId, $userId) !== null;
	}

	/**
	 * The latest markup generation of the user fed from the buffer, null when there is none.
	 * The database matches the identifier in the generation state; only matching states are read.
	 */
	public function findUsedByGenerationId(string $uploadId, int $userId): ?int
	{
		if ($uploadId === '' || $userId <= 0)
		{
			return null;
		}

		$generations = GenerationsTable::query()
			->setSelect(['ID', 'DATA'])
			->whereIn('SCENARIO', self::MARKUP_SCENARIOS)
			->where('CREATED_BY_ID', '=', $userId)
			->whereLike('DATA', $this->buildStateLikePattern($uploadId))
			->setOrder(['ID' => 'DESC'])
			->setLimit(self::USED_BY_GENERATION_LOOKUP_LIMIT)
			->exec()
		;
		while ($generation = $generations->fetch())
		{
			if ($this->refersToUpload($generation, $uploadId))
			{
				return (int)$generation['ID'];
			}
		}

		return null;
	}

	protected function readDiskFile(int $objectId, int $userId): Result
	{
		return Connector\Disk::readFileForUser($objectId, $userId, Validator::LIMIT_FILE_SIZE);
	}

	protected function getValidator(): Validator
	{
		return new Validator();
	}

	/**
	 * Writes the bytes of a buffer file or part, returns the b_file id; 0 when nothing was stored.
	 */
	protected function saveBytes(string $content): int
	{
		return FileStore::saveEntry($content);
	}

	protected function getCleanupBatchSize(): int
	{
		return self::CLEANUP_BATCH_SIZE;
	}

	/**
	 * Checks a whole file (path, size, type by content, package limits) and stores it.
	 * @param callable(Error): Result $refuse
	 */
	private function acceptFile(array $record, string $path, string $content, callable $refuse): Result
	{
		$error = $this->getValidator()->validateFileIntake($path, $content)
			?? $this->checkFill($record, $path, strlen($content))
		;
		if ($error !== null)
		{
			return $refuse($error);
		}

		$stored = $this->storeFile($record, $path, $content, self::ENCODING_BASE64);
		if (!$stored->isSuccess())
		{
			return $refuse($stored->getErrors()[0]);
		}

		return (new Result())->setData($this->describeFile($stored->getData()));
	}

	private function refusePart(array $record, string $path, Error $error): Result
	{
		$parts = $record['parts'][$path] ?? [];
		if ($parts)
		{
			unset($record['parts'][$path]);
			if ($this->saveRow($record['id'], $record['files'], $record['parts']))
			{
				File::deleteMarkupUploadParts($record['id'], array_column($parts, 'fileId'));
			}
		}

		return (new Result())->addError($error);
	}

	/**
	 * Stores a whole file: a repeated path replaces the file and drops its unfinished parts,
	 * unchanged bytes are not re-stored.
	 * @return Result Success data: the file entry.
	 */
	private function storeFile(array $record, string $path, string $content, string $encoding): Result
	{
		$hash = FileStore::hash($content);
		$files = $record['files'];
		$parts = $record['parts'];
		$releasedPartIds = array_column($parts[$path] ?? [], 'fileId');
		unset($parts[$path]);

		$replacedFileId = 0;
		foreach ($files as $index => $file)
		{
			if ($file['path'] !== $path)
			{
				continue;
			}
			if ($file['hash'] === $hash)
			{
				if (!$this->saveRow($record['id'], $files, $parts))
				{
					return $this->createStoreError($path);
				}
				File::deleteMarkupUploadParts($record['id'], $releasedPartIds);

				return (new Result())->setData($file);
			}
			$replacedFileId = (int)$file['fileId'];
			unset($files[$index]);
			break;
		}

		if (!$this->hasDiskQuotaFor(strlen($content)))
		{
			return (new Result())->addError($this->createQuotaError($path));
		}

		$fileId = $this->saveBytes($content);
		if ($fileId <= 0)
		{
			return $this->createStoreError($path);
		}
		File::addToMarkupUpload($record['id'], $fileId);

		$entry = [
			'path' => $path,
			'fileId' => $fileId,
			'hash' => $hash,
			'size' => strlen($content),
			'encoding' => $encoding,
		];
		$files[] = $entry;

		if (!$this->saveRow($record['id'], $files, $parts))
		{
			File::deleteFromMarkupUpload($record['id'], $fileId);

			return $this->createStoreError($path);
		}

		File::deleteMarkupUploadParts($record['id'], $releasedPartIds);
		if ($replacedFileId > 0)
		{
			File::deleteFromMarkupUpload($record['id'], $replacedFileId);
		}

		return (new Result())->setData($entry);
	}

	private function createStoreError(string $path): Result
	{
		return (new Result())->addError(new Error(
			'The file was not stored. Pass it again.',
			self::ERROR_CHUNK_MISMATCH,
			['path' => $path],
		));
	}

	private function createQuotaError(string $path): Error
	{
		return new Error(
			'The portal disk quota is exhausted, the file was not stored.',
			self::ERROR_DISK_QUOTA,
			['path' => $path],
		);
	}

	/**
	 * Releases everything the buffer row holds and removes the row: part files are deleted at once,
	 * whole files go to the deferred deletion of landing files.
	 * @return int Number of released part files.
	 */
	private function releaseRow(int $rowId, mixed $files): int
	{
		$partFileIds = [];
		foreach ((array)$files as $file)
		{
			if (is_array($file) && isset($file['offset'], $file['fileId']))
			{
				$partFileIds[] = (int)$file['fileId'];
			}
		}

		File::deleteMarkupUploadParts($rowId, $partFileIds);
		File::deleteFromMarkupUpload($rowId);
		MarkupUploadTable::delete($rowId);

		return count($partFileIds);
	}

	/**
	 * Releases an expired row under the lock of its row: a change that was running when the row was read
	 * has touched it, and such a row stays. A busy row is left for the next run.
	 * @return int|null Number of released part files, null when the row stays.
	 */
	private function releaseExpiredRow(int $rowId, DateTime $threshold): ?int
	{
		$lockName = 'landing_markup_upload_' . $rowId;
		if (!$this->lockRow($lockName))
		{
			return null;
		}

		try
		{
			$stored = MarkupUploadTable::query()
				->setSelect(['FILES'])
				->where('ID', '=', $rowId)
				->where('DATE_MODIFY', '<', $threshold)
				->fetch()
			;

			return $stored ? $this->releaseRow($rowId, $stored['FILES'] ?? []) : null;
		}
		finally
		{
			$this->unlockRow($lockName);
		}
	}

	/**
	 * Keeps the user below MAX_BUFFERS_PER_USER: drops the longest idle buffers no running generation
	 * refers to. False when the user holds the limit of buffers and none of them may be dropped.
	 */
	private function makeRoomForBuffer(int $userId): bool
	{
		$count = MarkupUploadTable::getCount(['=CREATED_BY_ID' => $userId]);
		if ($count < self::MAX_BUFFERS_PER_USER)
		{
			return true;
		}

		$rows = MarkupUploadTable::query()
			->setSelect(['ID', 'UID', 'CREATED_BY_ID', 'DATE_CREATE', 'FILES'])
			->where('CREATED_BY_ID', '=', $userId)
			->setOrder(['DATE_MODIFY' => 'ASC', 'ID' => 'ASC'])
			->fetchAll()
		;
		foreach ($rows as $row)
		{
			if ($count < self::MAX_BUFFERS_PER_USER)
			{
				break;
			}
			if ($this->isHeldByLiveGeneration($row))
			{
				continue;
			}

			$this->releaseRow((int)$row['ID'], $row['FILES'] ?? []);
			$count--;
		}

		return $count < self::MAX_BUFFERS_PER_USER;
	}

	/**
	 * Writes the files and the unfinished parts of the buffer; both live in FILES,
	 * a part is told from a file by its offset.
	 */
	private function saveRow(int $rowId, array $files, array $parts): bool
	{
		$items = array_values($files);
		foreach ($parts as $path => $pathParts)
		{
			foreach ($pathParts as $part)
			{
				$items[] = ['path' => (string)$path] + $part;
			}
		}

		return MarkupUploadTable::update($rowId, [
			'FILES' => $items,
			'DATE_MODIFY' => $this->now(),
		])->isSuccess();
	}

	private function touchRow(int $rowId): void
	{
		MarkupUploadTable::update($rowId, ['DATE_MODIFY' => $this->now()]);
	}

	/**
	 * @return array{path: string, fileId: int, hash: string, size: int}
	 */
	private function describeFile(array $file): array
	{
		return [
			'path' => (string)$file['path'],
			'fileId' => (int)$file['fileId'],
			'hash' => (string)$file['hash'],
			'size' => (int)$file['size'],
		];
	}

	/**
	 * Runs a change of the buffer under the lock of its row. Parts of one path arriving in parallel,
	 * and files of different paths as well, are applied one after another: every change reads
	 * the row under the lock, since FILES is rewritten as a whole. The row is looked up for its id only.
	 */
	private function runLocked(string $uploadId, int $userId, callable $change): mixed
	{
		$rowId = $this->fetchOwnRowId($uploadId, $userId);
		if ($rowId <= 0)
		{
			return $change();
		}

		$lockName = 'landing_markup_upload_' . $rowId;
		if (!$this->lockRow($lockName))
		{
			throw new \RuntimeException('The upload buffer is busy with another call. Send the file again.');
		}

		try
		{
			return $change();
		}
		finally
		{
			$this->unlockRow($lockName);
		}
	}

	protected function lockRow(string $lockName): bool
	{
		return Application::getConnection()->lock($lockName, self::ROW_LOCK_TIMEOUT_SECONDS);
	}

	protected function unlockRow(string $lockName): void
	{
		Application::getConnection()->unlock($lockName);
	}

	/**
	 * The clock of the buffer lifetime.
	 */
	protected function now(): DateTime
	{
		return new DateTime();
	}

	private function getExpiryThreshold(): DateTime
	{
		return $this->now()->add('-' . self::TTL_SECONDS . ' seconds');
	}

	private function isExpired(array $row): bool
	{
		$dateModify = $row['DATE_MODIFY'] ?? null;
		if (
			$dateModify instanceof DateTime
			&& $dateModify->getTimestamp() >= $this->getExpiryThreshold()->getTimestamp()
		)
		{
			return false;
		}

		return !$this->isHeldByLiveGeneration($row);
	}

	/**
	 * A markup generation keeps the uploadId in its state; the one referring to the buffer
	 * belongs to the buffer owner and was created after the buffer. The database matches the
	 * identifier in the state of unfinished generations, only matching states are read.
	 *
	 * @param array<int, list<array{id: int, dateCreate: ?DateTime}>>|null $unfinished Unfinished
	 *     markup generations by author, loaded once per cleanup run; null looks them up for the row.
	 */
	private function isHeldByLiveGeneration(array $row, ?array $unfinished = null): bool
	{
		$uploadId = (string)$row['UID'];
		$dateCreate = ($row['DATE_CREATE'] ?? null) instanceof DateTime ? $row['DATE_CREATE'] : null;

		$query = GenerationsTable::query()
			->setSelect(['ID', 'DATA'])
			->whereLike('DATA', $this->buildStateLikePattern($uploadId))
		;
		if ($unfinished !== null)
		{
			$candidateIds = [];
			foreach ($unfinished[(int)$row['CREATED_BY_ID']] ?? [] as $generation)
			{
				if (
					$dateCreate === null
					|| $generation['dateCreate'] === null
					|| $generation['dateCreate']->getTimestamp() >= $dateCreate->getTimestamp()
				)
				{
					$candidateIds[] = $generation['id'];
				}
			}
			if (!$candidateIds)
			{
				return false;
			}
			$query->whereIn('ID', $candidateIds);
		}
		else
		{
			$query
				->whereIn('SCENARIO', self::MARKUP_SCENARIOS)
				->where('CREATED_BY_ID', '=', (int)$row['CREATED_BY_ID'])
				->whereNull('DATE_FINISHED')
			;
			if ($dateCreate !== null)
			{
				$query->where('DATE_CREATE', '>=', $dateCreate);
			}
		}

		$generations = $query->exec();
		while ($generation = $generations->fetch())
		{
			if (!$this->refersToUpload($generation, $uploadId))
			{
				continue;
			}

			$descriptor = Generation::findDescriptorById((int)$generation['ID']);
			if ($descriptor !== null && !$descriptor->isTerminal())
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array<int, list<array{id: int, dateCreate: ?DateTime}>> Unfinished markup generations by author.
	 */
	private function loadUnfinishedGenerations(): array
	{
		$byAuthor = [];
		$generations = GenerationsTable::query()
			->setSelect(['ID', 'CREATED_BY_ID', 'DATE_CREATE'])
			->whereIn('SCENARIO', self::MARKUP_SCENARIOS)
			->whereNull('DATE_FINISHED')
			->exec()
		;
		while ($generation = $generations->fetch())
		{
			$byAuthor[(int)$generation['CREATED_BY_ID']][] = [
				'id' => (int)$generation['ID'],
				'dateCreate' => $generation['DATE_CREATE'] instanceof DateTime ? $generation['DATE_CREATE'] : null,
			];
		}

		return $byAuthor;
	}

	/**
	 * The serialized state keeps the uploadId as a quoted string; the pattern is a prefilter, the match
	 * is confirmed by refersToUpload().
	 */
	private function buildStateLikePattern(string $uploadId): string
	{
		return '%"' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $uploadId) . '"%';
	}

	private function refersToUpload(array $generation, string $uploadId): bool
	{
		$upload = $generation['DATA'][MarkupSiteState::UPLOAD_KEY] ?? null;

		return is_array($upload) && ($upload['uploadId'] ?? null) === $uploadId;
	}

	private function fetchOwnRowId(string $uploadId, int $userId): int
	{
		if ($uploadId === '' || $userId <= 0)
		{
			return 0;
		}

		$row = MarkupUploadTable::query()
			->setSelect(['ID'])
			->where('UID', '=', $uploadId)
			->where('CREATED_BY_ID', '=', $userId)
			->fetch()
		;

		return $row ? (int)$row['ID'] : 0;
	}

	private function fetchOwnRow(string $uploadId, int $userId): ?array
	{
		if ($uploadId === '' || $userId <= 0)
		{
			return null;
		}

		$row = MarkupUploadTable::query()
			->setSelect(self::ROW_SELECT)
			->where('UID', '=', $uploadId)
			->where('CREATED_BY_ID', '=', $userId)
			->fetch()
		;

		return $row ?: null;
	}

	private function getByRowId(int $rowId, int $userId): ?array
	{
		$row = MarkupUploadTable::query()
			->setSelect(self::ROW_SELECT)
			->where('ID', '=', $rowId)
			->where('CREATED_BY_ID', '=', $userId)
			->fetch()
		;

		return $row ? $this->normalizeRow($row) : null;
	}

	/**
	 * @return array{
	 *     id: int,
	 *     uploadId: string,
	 *     userId: int,
	 *     target: array,
	 *     files: list<array>,
	 *     parts: array<string, list<array{offset: int, fileId: int, size: int, hash: string}>>
	 * }
	 */
	private function normalizeRow(array $row): array
	{
		$mode = (string)$row['TARGET_MODE'];
		$target = ['mode' => $mode];
		if ($mode === self::MODE_UPDATE)
		{
			$target['pageId'] = (int)$row['TARGET_ID'];
		}
		else
		{
			$target['bindingId'] = (int)$row['TARGET_ID'];
		}

		$files = [];
		$parts = [];
		foreach ((array)($row['FILES'] ?? []) as $file)
		{
			if (!is_array($file) || !isset($file['path'], $file['fileId']))
			{
				continue;
			}
			if (isset($file['offset']))
			{
				$parts[(string)$file['path']][] = [
					'offset' => (int)$file['offset'],
					'fileId' => (int)$file['fileId'],
					'size' => (int)($file['size'] ?? 0),
					'hash' => (string)($file['hash'] ?? ''),
				];
				continue;
			}
			$files[] = [
				'path' => (string)$file['path'],
				'fileId' => (int)$file['fileId'],
				'hash' => (string)($file['hash'] ?? ''),
				'size' => (int)($file['size'] ?? 0),
				'encoding' => (string)($file['encoding'] ?? self::ENCODING_TEXT),
			];
		}

		return [
			'id' => (int)$row['ID'],
			'uploadId' => (string)$row['UID'],
			'userId' => (int)$row['CREATED_BY_ID'],
			'target' => $target,
			'files' => $files,
			'parts' => $parts,
		];
	}
}

<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Restrictions;

use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\IO;
use Bitrix\Main\Web\HttpClient;
use CFile;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the beginning of the content of a file registered in b_file, or of an upload that is
 * still on its way there.
 *
 * The head is taken where the content lies: from the file system for the local storage and over
 * HTTP with a byte range for the cloud one. The object is never fetched whole, because the write
 * path waits for this read. Any obstacle on the way - the cloud module is off, the network is
 * down, the storage answers with something other than the requested range - ends the same way:
 * no head, the reason in the journal, and the signature step skipped by the caller.
 *
 * Reading from a cloud storage goes over the network, so it is given a budget: a number of reads,
 * the time they take together, and a run of refusals that says the storage gives nothing to anyone.
 * Once the budget is spent, the reads stop and the write is left to the name of the file - the same
 * degradation an unreadable object leads to, written down once instead of once per file.
 *
 * The budget belongs to a stretch of work, not to the process running it. A web request is one such
 * stretch and ends with it, but an agent, a step of a stepper and a console script go on for as long
 * as they are given, and a budget spent once inside them would leave the signature step off for
 * everything that comes after - other users and other portals of the same run included. So the
 * budget is opened for a length of time of its own and is taken anew once that length has passed:
 * whoever the caller is, the reads cost the network no more than the budget over that length, and
 * nothing has to be remembered by a long operation for the step to come back.
 *
 * A local read costs no network, yet a folder of thousands of files on the network file system of a
 * cluster adds up all the same. So one operation - one instance of the reader - is given a time of
 * its own, spent by its cloud and local reads together, and it goes on by the names once that time
 * is spent. The two kinds of storage stop apart otherwise: the cloud budget of the process and a
 * cloud storage that refuses everything stop the cloud reads alone, and the local files of the
 * operation are read on in the time it has left (@see self::isStopped()). A kind stopped once stays
 * stopped for the operation, a cloud budget taken anew meanwhile included. An operation over many
 * files takes the rows of b_file for them by a query per chunk (@see self::preloadFileRows()).
 *
 * A read skipped for the spent budget of its kind leaves the content unchecked, while a storage that
 * refuses everything or an object that cannot be read leaves it unreadable. An operation over content
 * that is already stored tells the two apart for every file (@see self::wasLastReadSkippedForBudget()).
 */
class ContentHeadReader
{
	protected const LOG_FEATURE = 'disk restrictions content head';

	// The write path waits for this read, so the storage is given less time than the defaults allow.
	protected const CONNECT_TIMEOUT = 1;
	protected const READ_TIMEOUT = 2;

	protected const MAX_CLOUD_READS = 50;
	protected const MAX_CLOUD_SECONDS = 5.0;
	protected const REFUSALS_TO_GIVE_UP = 3;

	// Time of the cloud and the local reads of one operation together.
	protected const MAX_OPERATION_SECONDS = 5.0;
	protected const FILE_ROWS_CHUNK_SIZE = 500;

	// Length of the stretch of work the budget is opened for. Longer than a write path of any
	// ordinary request, short enough for a run of hours to keep the signature step alive.
	protected const BUDGET_LIFETIME_SECONDS = 60.0;

	protected const STATUS_OK = 200;
	protected const STATUS_PARTIAL_CONTENT = 206;

	/**
	 * Statuses a storage answers with whatever object is asked for: the next file of the batch would
	 * meet the same answer, so they count towards giving up. A status about the object alone - it is
	 * gone, it is broken - says nothing about the ones after it.
	 */
	protected const STATUSES_OF_THE_STORAGE_ITSELF = [401, 403, 405, 416, 501];

	private static int $spentCloudReads = 0;
	private static float $spentCloudSeconds = 0.0;
	private static int $refusalsInARow = 0;
	private static ?string $cloudReadingStopReason = null;
	// Whether the cloud reads stopped for the count or the time of the budget, not for the refusals.
	private static bool $cloudBudgetSpent = false;
	private static float $budgetOpenedAt = 0.0;

	private float $spentOperationSeconds = 0.0;
	private bool $operationTimeSpent = false;
	private bool $cloudReadingStopped = false;
	// Whether the cloud reads of the operation stopped for the cloud budget, not for the refusals.
	private bool $cloudStoppedForBudget = false;
	private bool $lastReadSkippedForBudget = false;
	private bool $readingStoredContent = false;
	/** @var array<int, array|null> Rows of b_file by the id of the content; null for a row that is not there. */
	private array $preloadedFileRows = [];

	protected readonly LoggerInterface $logger;

	/**
	 * @param LoggerInterface|null $logger Journal of the module, the standard one by default.
	 */
	public function __construct(?LoggerInterface $logger = null)
	{
		$this->logger = $logger ?? LoggerFactory::create(feature: static::LOG_FEATURE);
	}

	/**
	 * Returns the leading bytes of the content, SignatureDetector::HEAD_LENGTH of them at most.
	 * Content that could not be read has no head: the answer is null and the caller goes on
	 * without the signature step.
	 *
	 * @param array|null $fileData Row of b_file (@see \CFile::getFileArray()).
	 * @return string|null
	 */
	public function readHead(?array $fileData): ?string
	{
		$this->lastReadSkippedForBudget = false;

		if (empty($fileData))
		{
			return null;
		}

		try
		{
			return $this->isStoredInCloud($fileData)
				? $this->readCloudHead($fileData)
				: $this->readLocalHead($fileData)
			;
		}
		catch (Throwable $exception)
		{
			$this->logDegradation('reading of the content head failed', $fileData, [
				'exception' => $exception->getMessage(),
			]);

			return null;
		}
	}

	/**
	 * Returns the leading bytes of content that lies at a path of its own: an upload is read
	 * where it waits to be stored, while it has no row in b_file yet. Content that could not be
	 * read has no head here as well.
	 *
	 * @param string|null $path Path of the file in the file system.
	 * @return string|null
	 */
	public function readHeadFromPath(?string $path): ?string
	{
		$this->lastReadSkippedForBudget = false;

		if ($path === null || $path === '')
		{
			return null;
		}

		try
		{
			$file = new IO\File($path);
			if (!$file->isExists())
			{
				$this->logDegradation('the content of the upload is missing', [], ['path' => $path]);

				return null;
			}

			return $this->readFileHead($file);
		}
		catch (Throwable $exception)
		{
			$this->logDegradation('reading of the content head failed', [], [
				'path' => $path,
				'exception' => $exception->getMessage(),
			]);

			return null;
		}
	}

	/**
	 * Returns the leading bytes of the content stored under this id of b_file. The row is resolved
	 * by the id alone, so no caller decides what the reader goes to: it is the row the batch has
	 * loaded, or the one the file layer keeps for the id. A read of a stopped kind of storage reads
	 * nothing, the row included: the kind is the one the caller knows, or, unknown, the one any
	 * kind would be stopped for - the spent time of the operation.
	 *
	 * @param int $fileId Id of the row of b_file.
	 * @param bool|null $inCloud Whether the content lies in a cloud storage, when the caller knows it.
	 * @return string|null
	 */
	public function readStoredHead(int $fileId, ?bool $inCloud = null): ?string
	{
		$this->lastReadSkippedForBudget = false;

		if ($fileId <= 0)
		{
			return null;
		}

		$stopped = $inCloud === null ? $this->isOperationTimeSpent() : $this->isStopped($inCloud);
		if ($stopped)
		{
			$this->lastReadSkippedForBudget = !$inCloud || $this->isCloudStopForBudget();

			return null;
		}

		if (array_key_exists($fileId, $this->preloadedFileRows))
		{
			$fileData = $this->preloadedFileRows[$fileId];
			unset($this->preloadedFileRows[$fileId]);
		}
		else
		{
			$fileData = $this->loadFileRow($fileId);
		}

		// A read of stored content belongs to the operation, which keeps the cloud reads it stopped
		// stopped; a row the caller brings is read on the cloud budget as it is now.
		$this->readingStoredContent = true;
		try
		{
			return $this->readHead($fileData);
		}
		finally
		{
			$this->readingStoredContent = false;
		}
	}

	/**
	 * Loads the rows of b_file for the content of many files, a query per chunk of them, for the
	 * reads of the operation that follow (@see self::readStoredHead()). A spent time of the operation
	 * loads nothing: no head would be read from the rows. The caller leaves out the files of a kind
	 * already stopped (@see self::isStopped()).
	 *
	 * @param int[] $fileIds Ids of the rows of b_file.
	 * @return void
	 */
	public function preloadFileRows(array $fileIds): void
	{
		if ($this->isOperationTimeSpent())
		{
			return;
		}

		$fileIds = array_values(array_unique(array_filter(
			array_map('intval', $fileIds),
			static fn (int $fileId): bool => $fileId > 0,
		)));

		foreach (array_chunk($fileIds, static::FILE_ROWS_CHUNK_SIZE) as $chunk)
		{
			$rows = $this->loadFileRows($chunk);
			foreach ($chunk as $fileId)
			{
				$this->preloadedFileRows[$fileId] = $rows[$fileId] ?? null;
			}
		}
	}

	/**
	 * Tells whether the reads of this kind of storage are over for the operation: its time is spent,
	 * or, for the cloud, the cloud budget of the process is spent or the storage is given up on.
	 */
	public function isStopped(bool $inCloud): bool
	{
		if ($this->isOperationTimeSpent())
		{
			return true;
		}

		return $inCloud && $this->isCloudStoppedForOperation();
	}

	/**
	 * Tells whether the last read of this reader gave no head because the budget of its kind was
	 * spent, rather than because the storage refuses or the object cannot be read.
	 */
	public function wasLastReadSkippedForBudget(): bool
	{
		return $this->lastReadSkippedForBudget;
	}

	/**
	 * The row the file layer keeps for the id, for a read whose row the operation has not loaded.
	 */
	protected function loadFileRow(int $fileId): ?array
	{
		return CFile::getFileArray($fileId) ?: null;
	}

	/**
	 * The same rows the file layer gives for the ids (@see \CFile::GetFromCache()): content replaced
	 * under a stable id is read from its latest replacement.
	 *
	 * @param int[] $fileIds
	 * @return array<int, array> Rows by the id they were asked by.
	 */
	protected function loadFileRows(array $fileIds): array
	{
		$idList = implode(',', array_map('intval', $fileIds));
		$result = Application::getConnection()->query("
			SELECT f.*, NULL AS VERSION_ORIGINAL_ID
			FROM b_file f
			WHERE f.ID IN ({$idList})
			UNION
			SELECT f.*, fv.ORIGINAL_ID AS VERSION_ORIGINAL_ID
			FROM b_file f
				INNER JOIN b_file_version fv ON fv.VERSION_ID = f.ID
			WHERE fv.ORIGINAL_ID IN ({$idList})
			ORDER BY ID
		");

		$rows = [];
		while ($row = $result->fetch())
		{
			$rows[(int)($row['VERSION_ORIGINAL_ID'] ?: $row['ID'])] = $row;
		}

		foreach ($rows as $fileId => $row)
		{
			$rows[$fileId]['SRC'] = CFile::GetFileSRC($row);
		}

		return $rows;
	}

	/**
	 * A file of the cloud storage is the one bound to a bucket; the local one keeps no handler.
	 *
	 * @param mixed $handlerId HANDLER_ID of the row of b_file.
	 */
	public static function isCloudHandler(mixed $handlerId): bool
	{
		return is_numeric($handlerId) && (int)$handlerId > 0;
	}

	protected function isStoredInCloud(array $fileData): bool
	{
		return self::isCloudHandler($fileData['HANDLER_ID'] ?? null);
	}

	protected function readLocalHead(array $fileData): ?string
	{
		if ($this->isOperationTimeSpent())
		{
			$this->lastReadSkippedForBudget = true;

			return null;
		}

		$path = $this->getLocalPath($fileData);
		if ($path === null)
		{
			$this->logDegradation('the path of the local content is unknown', $fileData);

			return null;
		}

		$file = new IO\File($path);
		if (!$file->isExists())
		{
			$this->logDegradation('the local content is missing', $fileData);

			return null;
		}

		$startedAt = microtime(true);
		try
		{
			return $this->readFileHead($file);
		}
		finally
		{
			$this->spentOperationSeconds += microtime(true) - $startedAt;
		}
	}

	protected function isOperationTimeSpent(): bool
	{
		if ($this->spentOperationSeconds < static::MAX_OPERATION_SECONDS)
		{
			return false;
		}

		if (!$this->operationTimeSpent)
		{
			$this->operationTimeSpent = true;
			$this->noteCloudStopForSpentOperationTime();
			$this->logger->warning('the signature step of the operation is left out', [
				'reason' => 'the time allowed for content reads of the operation is spent',
				'seconds' => round($this->spentOperationSeconds, 3),
			]);
		}

		return true;
	}

	/**
	 * The cloud reads stopped once stay stopped for the operation: a cloud budget taken anew serves
	 * the operations after it.
	 */
	protected function isCloudStoppedForOperation(): bool
	{
		return $this->cloudReadingStopped || $this->noteCloudReadingStopped();
	}

	/**
	 * Tells whether the cloud reads are stopped for the process now, and remembers the first stop
	 * for the operation along with what caused it.
	 */
	protected function noteCloudReadingStopped(): bool
	{
		if (!$this->isCloudReadingStopped())
		{
			return false;
		}

		if (!$this->cloudReadingStopped)
		{
			$this->cloudReadingStopped = true;
			$this->cloudStoppedForBudget = self::$cloudBudgetSpent;
		}

		return true;
	}

	/**
	 * The spent time of the operation stops its cloud reads too, unless they are stopped already:
	 * the cause of the stop that came first stays the cause for the rest of the operation.
	 */
	protected function noteCloudStopForSpentOperationTime(): void
	{
		if ($this->isCloudStoppedForOperation())
		{
			return;
		}

		$this->cloudReadingStopped = true;
		$this->cloudStoppedForBudget = true;
	}

	/**
	 * A stopped cloud read is skipped for the budget when the cloud reads of the operation stopped first
	 * for the cloud budget of the process or for the spent time of the operation. A storage given up on
	 * for its refusals before that time was spent leaves the cloud files to their names to the end of
	 * the operation, the time spent after that included.
	 */
	protected function isCloudStopForBudget(): bool
	{
		return $this->cloudStoppedForBudget;
	}

	protected function readFileHead(IO\File $file): ?string
	{
		$handler = $file->open('r');

		try
		{
			$head = fread($handler, SignatureDetector::HEAD_LENGTH);
		}
		finally
		{
			$file->close();
		}

		return is_string($head) && $head !== '' ? $head : null;
	}

	/**
	 * The bucket answers the range with 206 and the head alone. A storage that knows no ranges
	 * answers 200 with the whole object, and the limit of the body breaks such a transfer as soon
	 * as it grows past the head: a full download in the write path is not an option, so an object
	 * that did not stop in time is left without a head at all.
	 */
	protected function readCloudHead(array $fileData): ?string
	{
		$url = (string)($fileData['SRC'] ?? '');
		if (!$this->isAbsoluteUrl($url))
		{
			$this->logDegradation('the cloud storage gives no url of the content', $fileData);

			return null;
		}

		if ($this->isOperationTimeSpent())
		{
			$this->lastReadSkippedForBudget = $this->isCloudStopForBudget();

			return null;
		}

		$stopped = $this->readingStoredContent
			? $this->isCloudStoppedForOperation()
			: $this->noteCloudReadingStopped()
		;
		if ($stopped)
		{
			$this->lastReadSkippedForBudget = $this->readingStoredContent
				? $this->isCloudStopForBudget()
				: self::$cloudBudgetSpent
			;

			return null;
		}

		$httpClient = $this->createHttpClient();
		$httpClient->setBodyLengthMax(SignatureDetector::HEAD_LENGTH);
		$httpClient->setHeader('Range', 'bytes=0-' . (SignatureDetector::HEAD_LENGTH - 1));

		$startedAt = microtime(true);
		$body = $httpClient->get($url);
		$spentSeconds = microtime(true) - $startedAt;
		$this->spendCloudBudget($spentSeconds);
		$this->spentOperationSeconds += $spentSeconds;

		if ($body === false)
		{
			$this->logDegradation('the cloud storage was not reached', $fileData, [
				'error' => $httpClient->getError(),
			]);
			$this->countCloudRefusal('the cloud storage is not reachable');

			return null;
		}

		$status = $httpClient->getStatus();
		if ($status !== static::STATUS_PARTIAL_CONTENT && $status !== static::STATUS_OK)
		{
			$this->logDegradation('the cloud storage refused to give the content', $fileData, [
				'status' => $status,
			]);

			if (in_array((int)$status, static::STATUSES_OF_THE_STORAGE_ITSELF, true))
			{
				$this->countCloudRefusal('the cloud storage gives no content, status ' . (int)$status);
			}

			return null;
		}

		self::$refusalsInARow = 0;

		$head = substr($body, 0, SignatureDetector::HEAD_LENGTH);

		return $head === '' ? null : $head;
	}

	/**
	 * Opens a budget anew, forgetting what was spent on the cloud storage before. A long operation
	 * may call this on its own boundary - the beginning of a step, the beginning of an iteration -
	 * to start it from a whole budget instead of waiting for the current one to run out its length.
	 * Nothing has to: a budget older than its length is taken anew by the next read.
	 */
	public static function resetBudget(): void
	{
		self::$spentCloudReads = 0;
		self::$spentCloudSeconds = 0.0;
		self::$refusalsInARow = 0;
		self::$cloudReadingStopReason = null;
		self::$cloudBudgetSpent = false;
		self::$budgetOpenedAt = 0.0;
	}

	/**
	 * Tells whether the cloud storage is worth asking at all. The answer is remembered, so the
	 * journal keeps one record of the degradation instead of one per file of a batch.
	 */
	protected function isCloudReadingStopped(): bool
	{
		$this->renewBudgetIfSpentLongAgo();

		if (self::$cloudReadingStopReason !== null)
		{
			return true;
		}

		if (self::$spentCloudReads >= static::MAX_CLOUD_READS)
		{
			self::$cloudBudgetSpent = true;
			$this->stopCloudReading('the number of content reads allowed by the budget is spent');

			return true;
		}

		if (self::$spentCloudSeconds >= static::MAX_CLOUD_SECONDS)
		{
			self::$cloudBudgetSpent = true;
			$this->stopCloudReading('the time allowed for content reads by the budget is spent');

			return true;
		}

		return false;
	}

	/**
	 * The budget lives for a length of time and is taken anew once that length has passed. This is
	 * what keeps a run of hours from turning a budget spent in its first minute into a signature
	 * step switched off until the process ends.
	 */
	protected function renewBudgetIfSpentLongAgo(): void
	{
		$now = microtime(true);

		if (self::$budgetOpenedAt <= 0.0)
		{
			self::$budgetOpenedAt = $now;

			return;
		}

		if ($now - self::$budgetOpenedAt < static::BUDGET_LIFETIME_SECONDS)
		{
			return;
		}

		$stopReason = self::$cloudReadingStopReason;

		self::resetBudget();
		self::$budgetOpenedAt = $now;

		if ($stopReason !== null)
		{
			$this->logger->info('the signature step is taken up again on a new read budget', [
				'reason' => $stopReason,
			]);
		}
	}

	protected function spendCloudBudget(float $seconds): void
	{
		self::$spentCloudReads++;
		self::$spentCloudSeconds += $seconds;
	}

	/**
	 * A refusal of the storage itself repeated often enough means the next file of the batch has
	 * nothing to gain from the same call.
	 */
	protected function countCloudRefusal(string $reason): void
	{
		self::$refusalsInARow++;

		if (self::$refusalsInARow >= static::REFUSALS_TO_GIVE_UP)
		{
			$this->stopCloudReading($reason);
		}
	}

	protected function stopCloudReading(string $reason): void
	{
		self::$cloudReadingStopReason = $reason;

		$this->logger->warning('the signature step is left out until the read budget is taken anew', [
			'reason' => $reason,
			'reads' => self::$spentCloudReads,
			'seconds' => round(self::$spentCloudSeconds, 3),
		]);
	}

	/**
	 * The client reads a range of bytes of an object whose address is already known, so it needs
	 * neither of the two liberties of the defaults. A redirect is nothing this read has any use
	 * for: it would take the request of the portal to an address the storage names - the loopback
	 * of the portal itself among them - and multiply the timeout the write path waits for by the
	 * length of the chain. Private addresses are refused for the same reason: the storage answers
	 * from the outside, and a redirect into the inner network is the only way one would be asked
	 * for here.
	 */
	protected function createHttpClient(): HttpClient
	{
		return (new HttpClient())
			->setTimeout(static::CONNECT_TIMEOUT)
			->setStreamTimeout(static::READ_TIMEOUT)
			->setRedirect(false)
			->setPrivateIp(false)
		;
	}

	protected function getLocalPath(array $fileData): ?string
	{
		$fileName = (string)($fileData['FILE_NAME'] ?? '');
		if ($fileName === '')
		{
			return null;
		}

		return IO\Path::combine(
			$this->getUploadDirectory(),
			(string)($fileData['SUBDIR'] ?? ''),
			$fileName,
		);
	}

	protected function getUploadDirectory(): string
	{
		return IO\Path::combine(
			Application::getDocumentRoot(),
			(string)Option::get('main', 'upload_dir', 'upload'),
		);
	}

	protected function isAbsoluteUrl(string $url): bool
	{
		return preg_match('#^https?://#i', $url) === 1;
	}

	protected function logDegradation(string $reason, array $fileData, array $context = []): void
	{
		$this->logger->warning($reason, array_merge(
			['fileId' => (int)($fileData['ID'] ?? 0)],
			$context,
		));
	}
}

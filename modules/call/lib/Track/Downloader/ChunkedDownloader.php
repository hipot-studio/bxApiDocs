<?php

namespace Bitrix\Call\Track\Downloader;

use Bitrix\Main\IO;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Call;
use Bitrix\Call\Track;
use Bitrix\Call\Track\TrackError;
use Bitrix\Call\Track\TrackService;
use Bitrix\Call\Logger\Logger;
use Bitrix\Call\Integration\AI\CallAISettings;

/**
 * Downloads track file in chunks with time limit awareness and resume capability
 */
class ChunkedDownloader extends AbstractDownloader
{
	private const CHUNK_SIZE = 10485760; // 10 MB
	private const EXECUTION_TIME_LIMIT = 25; // seconds
	private const CHUNK_RESUME_DELAY = 5; // seconds between chunk resumptions
	private const SYNC_MAX_RETRIES = 5; // max retries waiting for file sync

	private int $fileSize;
	private float $startTime;
	private int $downloadedBytes = 0;

	/**
	 * @param int $fileSize Known file size from Range support check
	 * @param float|null $startTime Request start time (defaults to START_EXEC_TIME or current time)
	 */
	public function __construct(int $fileSize)
	{
		$this->fileSize = $fileSize;
		$this->startTime = defined('START_EXEC_TIME') ? START_EXEC_TIME : microtime(true);
	}

	/**
	 * Check if execution time limit has been reached
	 */
	protected function isExecutionLimitReached(): bool
	{
		return php_sapi_name() !== 'cli' && (microtime(true) - $this->startTime) >= self::EXECUTION_TIME_LIMIT;
	}

	/**
	 * Check if download can continue (time limit not reached and file not complete)
	 */
	protected function canContinueDownload(): bool
	{
		return !$this->isExecutionLimitReached()
			&& $this->downloadedBytes < $this->fileSize;
	}

	/**
	 * @inheritDoc
	 */
	public function download(Track $track): Result
	{
		$result = new Result();
		$log = CallAISettings::isLoggingEnable();
		$logger = Logger::getInstance();

		$downloadUrl = $track->getDownloadUrl();
		$tempPath = $track->getTempPath();

		// Initialize temp path if not set
		if (empty($tempPath))
		{
			$track->generateTemporaryPath();
			$tempPath = $track->getTempPath();
			$track->save();
			$log && $logger->info("ChunkedDownloader::download: TempPath created: {$tempPath}. TrackId: {$track->getId()}");
		}

		// Get current progress from file size on disk
		$this->downloadedBytes = 0;
		$file = new IO\File($tempPath);
		if ($file->isExists())
		{
			$this->downloadedBytes = (int)$file->getSize();
			$log && $logger->info("ChunkedDownloader::download: Resuming. Downloaded: {$this->downloadedBytes}/{$this->fileSize} bytes. TrackId: {$track->getId()}");
		}

		// Ensure directory exists
		IO\Directory::createDirectory(\dirname($tempPath));

		$log && $logger->info(
			"ChunkedDownloader::download: Starting. TrackId: {$track->getId()}, "
			. "FileSize: {$this->fileSize}, Downloaded: {$this->downloadedBytes}, ChunkSize: " . self::CHUNK_SIZE
		);

		try
		{
			$fileHandle = $file->open('a');
		}
		catch (IO\FileOpenException $e)
		{
			$log && $logger->error("ChunkedDownloader::download: Cannot open file: {$tempPath}");
			return $this->fail($result->addError(new TrackError(
				TrackError::DOWNLOAD_ERROR,
				'Cannot open temp file for writing: ' . $tempPath
			)));
		}

		// Download chunks until time limit or completion
		$chunkCount = 0;
		try
		{
			while ($this->canContinueDownload())
			{
				$chunkCount++;
				$startByte = $this->downloadedBytes;
				$endByte = min($this->downloadedBytes + self::CHUNK_SIZE - 1, $this->fileSize - 1);
				$percent = round(($this->downloadedBytes / $this->fileSize) * 100, 1);

				$log && $logger->info(
					"ChunkedDownloader::download: Chunk #{$chunkCount}. "
					. "Bytes: {$startByte}-{$endByte}, Progress: {$percent}%. TrackId: {$track->getId()}"
				);

				$chunkResult = $this->downloadChunk($downloadUrl, $startByte, $endByte, $fileHandle, $this->fileSize);
				if (!$chunkResult->isSuccess())
				{
					$log && $logger->error("ChunkedDownloader::download: Chunk #{$chunkCount} failed. TrackId: {$track->getId()}");
					$result->addErrors($chunkResult->getErrors());
					return $this->fail($result);
				}

				$bytesWritten = $chunkResult->getData()['bytes_written'];
				$this->downloadedBytes += $bytesWritten;

				if ($this->downloadedBytes >= $this->fileSize)
				{
					$log && $logger->info("ChunkedDownloader::download: Completed at chunk #{$chunkCount}. TrackId: {$track->getId()}");
					break;
				}
			}
		}
		finally
		{
			$file->close();
		}

		$completed = ($this->downloadedBytes >= $this->fileSize);

		$totalTime = round(microtime(true) - $this->startTime, 1);

		if ($completed)
		{
			$log && $logger->info(
				"ChunkedDownloader::download: Completed. {$this->fileSize} bytes, "
				. "{$chunkCount} chunks, {$totalTime}s. TrackId: {$track->getId()}"
			);
			return $this->complete($result, $track);
		}

		// Download not finished — schedule agent for continuation
		$percent = round(($this->downloadedBytes / $this->fileSize) * 100, 1);
		$log && $logger->info(
			"ChunkedDownloader::download: Time limit. {$percent}% ({$this->downloadedBytes}/{$this->fileSize}), "
			. "{$chunkCount} chunks, {$totalTime}s. TrackId: {$track->getId()}"
		);

		self::scheduleChunkResume($track->getId(), $this->downloadedBytes);

		return $this->progress($result);
	}

	/**
	 * Download a single chunk of the file.
	 *
	 * @param string $url Download URL
	 * @param int $startByte Starting byte position
	 * @param int $endByte Ending byte position
	 * @param resource $fileHandle File handle for writing
	 * @param int $expectedTotalSize Expected total file size (for validation)
	 * @return Result Contains 'bytes_written' on success
	 */
	protected function downloadChunk(string $url, int $startByte, int $endByte, $fileHandle, int $expectedTotalSize = 0): Result
	{
		$result = new Result();
		$log = CallAISettings::isLoggingEnable();
		$logger = Logger::getInstance();

		$log && $logger->info("ChunkedDownloader::downloadChunk: Starting. Bytes: {$startByte}-{$endByte}");

		$httpClient = DownloadHelper::createHttpClient();
		$httpClient->setHeader('Range', "bytes={$startByte}-{$endByte}");
		$httpClient->setOutputStream($fileHandle);
		$queryResult = $httpClient->query(HttpClient::HTTP_GET, $url);
		$httpClient->getResult();

		$status = $httpClient->getStatus();
		$log && $logger->info("ChunkedDownloader::downloadChunk: HTTP completed. Status: {$status}");

		if (!$queryResult || $status !== 206)
		{
			$errors = [];
			foreach ($httpClient->getError() as $code => $message)
			{
				$errors[] = $code . ': ' . $message;
			}
			$errorMessage = !empty($errors) ? implode('; ', $errors) : "Expected HTTP 206, got: {$status}";

			$log && $logger->error("ChunkedDownloader::downloadChunk: Failed: {$errorMessage}. Bytes: {$startByte}-{$endByte}");

			return $result->addError(new TrackError(
				TrackError::DOWNLOAD_ERROR,
				"Chunk download failed: {$errorMessage}"
			));
		}

		// Validate Content-Range header if present
		$contentRange = $httpClient->getHeaders()->get('Content-Range');
		$log && $logger->info("ChunkedDownloader::downloadChunk: Content-Range: '{$contentRange}'");

		if ($contentRange && preg_match('/bytes (\d+)-(\d+)\/(\d+)/', $contentRange, $matches))
		{
			$totalSize = (int)$matches[3];
			if ($expectedTotalSize > 0 && $totalSize !== $expectedTotalSize)
			{
				$log && $logger->warning(
					"ChunkedDownloader::downloadChunk: File size changed: expected {$expectedTotalSize}, got {$totalSize}"
				);
				return $result->addError(new TrackError(
					TrackError::FILE_SIZE_MISMATCH,
					"File size changed during download: expected {$expectedTotalSize}, got {$totalSize}"
				));
			}
		}

		$bytesWritten = $endByte - $startByte + 1;
		$log && $logger->info("ChunkedDownloader::downloadChunk: Success. BytesWritten: {$bytesWritten}");

		return $result->setData(['bytes_written' => $bytesWritten]);
	}

	/**
	 * Schedule agent for chunk resume (НЕ retry, а продолжение)
	 *
	 * @param int $trackId
	 * @param int $expectedBytes Expected file size after current session (for sync check)
	 * @param int $delay Delay in seconds
	 */
	public static function scheduleChunkResume(int $trackId, int $expectedBytes, int $delay = self::CHUNK_RESUME_DELAY): void
	{
		$log = CallAISettings::isLoggingEnable();
		$logger = Logger::getInstance();

		$agentName = self::class . "::chunkResumeAgent({$trackId}, {$expectedBytes}, 0);";

		$agents = \CAgent::getList([], [
			'MODULE_ID' => 'call',
			'NAME' => $agentName
		]);

		if ($agents->fetch())
		{
			$log && $logger->info("ChunkedDownloader::scheduleChunkResume: Agent already exists. TrackId: {$trackId}");
			return;
		}

		$log && $logger->info("ChunkedDownloader::scheduleChunkResume: Creating agent. TrackId: {$trackId}, ExpectedBytes: {$expectedBytes}, Delay: {$delay}s");

		/** @see self::chunkResumeAgent() */
		\CAgent::AddAgent(
			$agentName,
			'call',
			'N',
			$delay,
			'',
			'Y',
			\ConvertTimeStamp(\time() + \CTimeZone::GetOffset() + $delay, 'FULL')
		);
	}

	/**
	 * Agent for resuming chunked download.
	 * Calls download() directly without going through TrackService::downloadTrackFile().
	 *
	 * @param int $trackId
	 * @param int $expectedBytes Expected file size from previous session (for sync check)
	 * @param int $syncRetry Current retry count for waiting file sync
	 * @return string Empty string to stop, or agent call to reschedule
	 */
	public static function chunkResumeAgent(int $trackId, int $expectedBytes = 0, int $syncRetry = 0): string
	{
		if (!Loader::includeModule('call'))
		{
			return '';
		}

		$log = CallAISettings::isLoggingEnable();
		$logger = Logger::getInstance();

		$log && $logger->info("ChunkedDownloader::chunkResumeAgent: Started. TrackId: {$trackId}");

		$track = Call\Model\CallTrackTable::getById($trackId)->fetchObject();
		if (!$track)
		{
			$log && $logger->error("ChunkedDownloader::chunkResumeAgent: Track not found. TrackId: {$trackId}");
			return '';
		}

		if ($track->getDownloaded() === true)
		{
			$log && $logger->info("ChunkedDownloader::chunkResumeAgent: Track already downloaded. TrackId: {$trackId}");
			return '';
		}

		$tempPath = $track->getTempPath();
		$actualBytes = 0;
		if (!empty($tempPath))
		{
			\clearstatcache(true, $tempPath);
			$file = new IO\File($tempPath);
			$actualBytes = $file->isExists() ? (int)$file->getSize() : 0;
		}

		$log && $logger->info(
			"ChunkedDownloader::chunkResumeAgent: Expected: {$expectedBytes}, Actual: {$actualBytes}. TrackId: {$trackId}"
		);

		if ($expectedBytes > 0 && $actualBytes < $expectedBytes)
		{
			if ($syncRetry < self::SYNC_MAX_RETRIES)
			{
				$log && $logger->info(
					"ChunkedDownloader::chunkResumeAgent: File not synced. "
					. "Retry " . ($syncRetry + 1) . "/" . self::SYNC_MAX_RETRIES . ". TrackId: {$trackId}"
				);
				return __METHOD__ . "({$trackId}, {$expectedBytes}, " . ($syncRetry + 1) . ");";
			}

			$log && $logger->warning(
				"ChunkedDownloader::chunkResumeAgent: Max sync retries reached. "
				. "Restarting from scratch. TrackId: {$trackId}"
			);

			// Delete corrupted temp file
			$tempPath = $track->getTempPath();
			if ($tempPath)
			{
				$tempFile = new \Bitrix\Main\IO\File($tempPath);
				if ($tempFile->isExists())
				{
					$tempFile->delete();
				}
			}

			// Generate new temp path and save
			$track->generateTemporaryPath()->save();
		}

		$downloader = new self($track->getFileSize());
		$downloader->setOnComplete(
			TrackService::getInstance()->onDownloadCompleteCallback()
		);

		$result = $downloader->download($track);
		$resultData = $result->getData();

		// Check if download is still in progress
		if (isset($resultData['status']) && $resultData['status'] === 'in_progress')
		{
			$log && $logger->info("ChunkedDownloader::chunkResumeAgent: Still in progress. TrackId: {$trackId}");
			return '';
		}

		if ($result->isSuccess())
		{
			$log && $logger->info("ChunkedDownloader::chunkResumeAgent: Completed successfully. TrackId: {$trackId}");
		}
		else
		{
			$log && $logger->error(
				"ChunkedDownloader::chunkResumeAgent: Failed. TrackId: {$trackId}. "
				. "Errors: " . implode('; ', $result->getErrorMessages())
			);
		}

		return '';
	}
}

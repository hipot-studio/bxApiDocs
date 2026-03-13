<?php

namespace Bitrix\Call\Track\Downloader;

use Bitrix\Main\IO;
use Bitrix\Main\Result;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Loader;
use Bitrix\Call\Track;
use Bitrix\Call\Track\TrackError;
use Bitrix\Call\Logger\Logger;
use Bitrix\Call\Integration\AI\CallAISettings;
use Bitrix\Call\Analytics\FollowUpAnalytics;
use Bitrix\Call\Call\Registry;

/**
 * Downloads track file in chunks with time limit awareness and resume capability
 *
 * @internal
 */
class ChunkedDownloader extends AbstractDownloader
{
	private const CHUNK_SIZE = 10485760; // 10 MB
	private const EXECUTION_TIME_LIMIT = 25; // seconds

	private int $fileSize;
	private float $startTime;
	private int $downloadedBytes = 0;

	/**
	 * @param int $fileSize Known file size from Range support check
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

		// Check if execution time already exceeded - reschedule immediately
		if ($this->isExecutionLimitReached())
		{
			$this->sendTelemetry($track, 'in_progress', null, $this->getEventName($track, 'finished_by_execution_timelimit'));

			$log && $logger->info("ChunkedDownloader::download: Time limit exceeded at start, rescheduling. TrackId: {$track->getId()}");
			return $this->progress($result, $this->downloadedBytes);
		}

		// Ensure directory exists
		IO\Directory::createDirectory(\dirname($tempPath));

		$log && $logger->info(
			"ChunkedDownloader::download: Starting. TrackId: {$track->getId()}, "
			. "FileSize: {$this->fileSize}, Downloaded: {$this->downloadedBytes}, ChunkSize: " . self::CHUNK_SIZE
		);

		// Send telemetry about download start
		$this->sendTelemetry($track, 'success', null, $this->getEventName($track, 'download_started'));

		try
		{
			$fileHandle = $file->open('a');
		}
		catch (IO\FileOpenException $e)
		{
			$log && $logger->error("ChunkedDownloader::download: Cannot open file: {$tempPath}");

			// Send telemetry about error
			$this->sendTelemetry($track, 'error', 'file_open_error', $this->getEventName($track, 'download_failed'));

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

					$errors = $chunkResult->getErrors();
					$errorCode = !empty($errors) ? $errors[0]->getCode() : 'unknown';
					$this->sendTelemetry($track, 'error', $errorCode, $this->getEventName($track, 'download_chunk_failed'));

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

			// Send telemetry about successful download completion
			$this->sendTelemetry($track, 'success', null, $this->getEventName($track, 'download_completed'));

			return $this->complete($result, $track);
		}

		// Download not finished — return progress status
		$percent = round(($this->downloadedBytes / $this->fileSize) * 100, 1);
		$log && $logger->info(
			"ChunkedDownloader::download: Time limit. {$percent}% ({$this->downloadedBytes}/{$this->fileSize}), "
			. "{$chunkCount} chunks, {$totalTime}s. TrackId: {$track->getId()}"
		);

		$this->sendTelemetry($track, 'success', null, $this->getEventName($track, 'download_in_progress'));

		return $this->progress($result, $this->downloadedBytes);
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
			$httpErrors = $httpClient->getError();
			$isNetworkError = isset($httpErrors['NETWORK']);

			$errors = array_values($httpErrors);
			if ($status != 206)
			{
				$errors[] = "Expected HTTP 206, got: {$status}";
			}
			$errors[] = 'url: ' . (parse_url($url, PHP_URL_HOST) ?: $url);

			$errorMessage = implode('; ', $errors);

			$log && $logger->error("ChunkedDownloader::downloadChunk: Failed: {$errorMessage}. Bytes: {$startByte}-{$endByte}");

			$errorCode = TrackError::DOWNLOAD_ERROR;
			if ($isNetworkError)
			{
				$errorCode = TrackError::NETWORK_ERROR;

				$systemException = new \Bitrix\Main\SystemException('Network connection error: ' . $errorMessage);
				\Bitrix\Main\Application::getInstance()->getExceptionHandler()->writeToLog($systemException);
			}

			return $result->addError(new TrackError(
				$errorCode,
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
	 * Get event name based on track type
	 *
	 * @param Track $track Track entity
	 * @param string $action Action suffix (e.g., 'download_started')
	 * @return string Event name
	 */
	protected function getEventName(Track $track, string $action): string
	{
		return "chunked_downloader_{$action}_{$track->getId()}";
	}

	/**
	 * Send telemetry if call can be loaded
	 *
	 * @param Track $track Track entity
	 * @param string $status Status ('success' or 'error')
	 * @param string|null $errorCode Optional error code
	 * @param string $event Event name
	 */
	protected function sendTelemetry(
		Track $track,
		string $status,
		?string $errorCode,
		string $event
	): void
	{
		if (!Loader::includeModule('im'))
		{
			return;
		}

		$call = Registry::getCallWithId($track->getCallId());
		if (!$call)
		{
			return;
		}

		(new FollowUpAnalytics($call))
			->sendTelemetry(
				source: null,
				status: $status,
				errorCode: $errorCode,
				event: $event
			);
	}
}

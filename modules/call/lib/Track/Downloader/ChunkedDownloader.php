<?php

namespace Bitrix\Call\Track\Downloader;

use Bitrix\Main\Result;
use Psr\Log\LoggerInterface;
use Bitrix\Call\Track;
use Bitrix\Call\Track\TrackError;
use Bitrix\Call\Logger\Logger;
use Bitrix\Call\Integration\AI\CallAISettings;
use Bitrix\Call\Analytics\TrackAnalytics;
use Bitrix\Call\Call\Registry;
use Bitrix\Call\Download\ChunkedDownloadEngine;
use Bitrix\Call\Download\DownloadFailure;
use Bitrix\Call\Download\DownloadFailureReason;
use Bitrix\Call\Download\DownloadJob;
use Bitrix\Call\Download\DownloadObserver;
use Bitrix\Call\Download\DownloadPolicy;
use Bitrix\Call\Download\DownloadStatus;

/**
 * Downloads track file in chunks with time limit awareness and resume capability.
 *
 * The chunk loop itself is {@see ChunkedDownloadEngine}, driven with the track policy
 * {@see DownloadPolicy::forTracks()}; this class keeps what is the track's own around it - the
 * temp path of the row, the log lines, the telemetry, the track errors and the completion.
 * The hooks below are called by the engine at the points the loop used to log and report from.
 *
 * @internal
 */
class ChunkedDownloader extends AbstractDownloader implements DownloadObserver
{
	// Defaults for the tuning options (see DownloadPolicy::forTracks()).
	public const CHUNK_SIZE = DownloadPolicy::DEFAULT_CHUNK_SIZE; // 10 MB
	public const EXECUTION_TIME_LIMIT = DownloadPolicy::DEFAULT_STEP_BUDGET; // seconds

	private int $fileSize;
	private float $startTime;
	private DownloadPolicy $policy;
	private int $chunkSize;
	private int $executionTimeLimit; // the step budget the engine enforces, as read from the options

	private ?Track $track = null;
	private bool $log = false;
	private ?LoggerInterface $logger = null;
	private string $contentRange = '';
	private bool $forcedNetworkError = false;
	private bool $budgetSpentBeforeStart = false;
	private ?TrackError $chunkError = null;

	/**
	 * @param int $fileSize Known file size from Range support check
	 */
	public function __construct(int $fileSize)
	{
		$this->fileSize = $fileSize;
		$this->startTime = defined('START_EXEC_TIME') ? START_EXEC_TIME : microtime(true);
		// Tuning options are read once per instance, not per chunk in the download loop.
		$this->policy = DownloadPolicy::forTracks();
		$this->chunkSize = $this->policy->chunkSize;
		$this->executionTimeLimit = $this->policy->stepBudgetSeconds;
	}

	/**
	 * @inheritDoc
	 */
	public function download(Track $track): Result
	{
		$result = new Result();
		$log = CallAISettings::isLoggingEnable();
		$logger = Logger::getInstance();

		$tempPath = $track->getTempPath();

		// Initialize temp path if not set
		if (empty($tempPath))
		{
			$track->generateTemporaryPath();
			$tempPath = $track->getTempPath();
			$track->save();
			$log && $logger->info("ChunkedDownloader::download: TempPath created: {$tempPath}. TrackId: {$track->getId()}");
		}

		$this->track = $track;
		$this->log = $log;
		$this->logger = $logger;
		$this->contentRange = '';
		$this->forcedNetworkError = false;
		$this->budgetSpentBeforeStart = false;
		$this->chunkError = null;

		$outcome = $this->createEngine()->step(new TrackDownloadJob($track, $this->fileSize, $this->policy), $this);

		// The engine answers a throwable with an outcome; the chunk loop of tracks always let it through.
		if ($outcome->failure?->exception !== null)
		{
			throw $outcome->failure->exception;
		}

		if ($outcome->status === DownloadStatus::Failed)
		{
			if ($this->chunkError !== null)
			{
				$result->addError($this->chunkError);

				return $this->fail($result);
			}

			return $this->failBeforeChunks($result, $track, $tempPath, $outcome->failure);
		}

		if ($this->budgetSpentBeforeStart)
		{
			return $this->progress($result, $outcome->downloadedBytes);
		}

		$totalTime = round(microtime(true) - $this->startTime, 1);

		if ($outcome->status === DownloadStatus::Completed)
		{
			$log && $logger->info(
				"ChunkedDownloader::download: Completed. {$this->fileSize} bytes, "
				. "{$outcome->chunks} chunks, {$totalTime}s. TrackId: {$track->getId()}"
			);

			// Send telemetry about successful download completion
			$this->sendTelemetry($track, 'success', null, $this->getEventName($track, 'download_completed'));

			return $this->complete($result, $track);
		}

		// Download not finished — return progress status
		$percent = round(($outcome->downloadedBytes / $this->fileSize) * 100, 1);
		$log && $logger->info(
			"ChunkedDownloader::download: Time limit. {$percent}% ({$outcome->downloadedBytes}/{$this->fileSize}), "
			. "{$outcome->chunks} chunks, {$totalTime}s. TrackId: {$track->getId()}"
		);

		$this->sendTelemetry($track, 'success', null, $this->getEventName($track, 'download_in_progress'));

		return $this->progress($result, $outcome->downloadedBytes);
	}

	/**
	 * The engine that runs the chunk loop; its time budget counts from the start of this downloader.
	 */
	protected function createEngine(): ChunkedDownloadEngine
	{
		return new ChunkedDownloadEngine($this->startTime);
	}

	/**
	 * A step that failed before its first chunk. The temp file that cannot be opened is the only
	 * such failure the track policy meets through the factory, which probes the url and the size
	 * before a chunked downloader exists.
	 */
	private function failBeforeChunks(Result $result, Track $track, string $tempPath, DownloadFailure $failure): Result
	{
		if ($failure->reason === DownloadFailureReason::FileError)
		{
			$this->log && $this->logger->error("ChunkedDownloader::download: Cannot open file: {$tempPath}");

			// Send telemetry about error
			$this->sendTelemetry($track, 'error', 'file_open_error', $this->getEventName($track, 'download_failed'));

			return $this->fail($result->addError(new TrackError(
				TrackError::DOWNLOAD_ERROR,
				'Cannot open temp file for writing: ' . $tempPath
			)));
		}

		$this->log && $this->logger->error("ChunkedDownloader::download: {$failure->message}. TrackId: {$track->getId()}");

		return $this->fail($result->addError(new TrackError(TrackError::DOWNLOAD_ERROR, $failure->message)));
	}

	public function onResume(DownloadJob $job, int $downloadedBytes): void
	{
		$this->log && $this->logger->info(
			"ChunkedDownloader::download: Resuming. Downloaded: {$downloadedBytes}/{$this->fileSize} bytes. TrackId: {$this->track->getId()}"
		);
	}

	public function onBudgetSpentBeforeStart(DownloadJob $job, int $downloadedBytes): void
	{
		$this->budgetSpentBeforeStart = true;
		$this->sendTelemetry($this->track, 'in_progress', null, $this->getEventName($this->track, 'finished_by_execution_timelimit'));

		$this->log && $this->logger->info("ChunkedDownloader::download: Time limit exceeded at start, rescheduling. TrackId: {$this->track->getId()}");
	}

	public function onStart(DownloadJob $job, int $downloadedBytes): void
	{
		$this->log && $this->logger->info(
			"ChunkedDownloader::download: Starting. TrackId: {$this->track->getId()}, "
			. "FileSize: {$this->fileSize}, Downloaded: {$downloadedBytes}, ChunkSize: {$this->chunkSize}"
		);

		// Send telemetry about download start
		$this->sendTelemetry($this->track, 'success', null, $this->getEventName($this->track, 'download_started'));
	}

	public function onChunkRequest(DownloadJob $job, int $chunk, int $startByte, int $endByte, int $downloadedBytes): bool
	{
		$percent = round(($downloadedBytes / $this->fileSize) * 100, 1);
		$this->log && $this->logger->info(
			"ChunkedDownloader::download: Chunk #{$chunk}. "
			. "Bytes: {$startByte}-{$endByte}, Progress: {$percent}%. TrackId: {$this->track->getId()}"
		);

		$log = CallAISettings::isLoggingEnable();
		$logger = Logger::getInstance();

		$log && $logger->info("ChunkedDownloader::downloadChunk: Starting. Bytes: {$startByte}-{$endByte}");

		// [DEBUG] Force NETWORK_ERROR for end-to-end testing of the retryable-recording flow.
		// Toggle: \Bitrix\Main\Config\Option::set('call', 'debug_force_network_error', true|false).
		// Returned through the normal chunked path so DownloadAgent retry counter,
		// telemetry and $this->fail($result) all fire as in production.
		// The debug suffix marks the telemetry payload with a distinct event/errorCode so the
		// synthetic event is not mistaken for a real network failure in analytics.
		if (filter_var(\Bitrix\Main\Config\Option::get('call', 'debug_force_network_error', ''), FILTER_VALIDATE_BOOLEAN))
		{
			$log && $logger->error("ChunkedDownloader::downloadChunk: Forced NETWORK_ERROR (debug_force_network_error). Bytes: {$startByte}-{$endByte}");
			$this->forcedNetworkError = true;

			return false;
		}

		return true;
	}

	public function onChunkResponse(DownloadJob $job, int $chunk, int $httpStatus, string $contentRange): void
	{
		$this->contentRange = $contentRange;

		$log = CallAISettings::isLoggingEnable();
		$log && Logger::getInstance()->info("ChunkedDownloader::downloadChunk: HTTP completed. Status: {$httpStatus}");
	}

	public function onChunkFailed(DownloadJob $job, int $chunk, int $startByte, int $endByte, DownloadFailure $failure): void
	{
		$log = CallAISettings::isLoggingEnable();
		$logger = Logger::getInstance();
		$debugSuffix = '';

		if ($this->forcedNetworkError)
		{
			$error = new TrackError(TrackError::NETWORK_ERROR, 'ChunkedDownloader: forced NETWORK_ERROR for testing');
			$debugSuffix = '_debug';
		}
		elseif ($failure->reason === DownloadFailureReason::SizeChanged)
		{
			$log && $logger->info("ChunkedDownloader::downloadChunk: Content-Range: '{$this->contentRange}'");

			preg_match('/bytes (\d+)-(\d+)\/(\d+)/', $this->contentRange, $matches);
			$totalSize = (int)$matches[3];
			$log && $logger->warning(
				"ChunkedDownloader::downloadChunk: File size changed: expected {$this->fileSize}, got {$totalSize}"
			);
			$error = new TrackError(
				TrackError::FILE_SIZE_MISMATCH,
				"File size changed during download: expected {$this->fileSize}, got {$totalSize}"
			);
		}
		else
		{
			$log && $logger->error("ChunkedDownloader::downloadChunk: Failed: {$failure->message}. Bytes: {$startByte}-{$endByte}");

			$errorCode = TrackError::DOWNLOAD_ERROR;
			if (isset($failure->httpErrors['NETWORK']))
			{
				$errorCode = TrackError::NETWORK_ERROR;

				$systemException = new \Bitrix\Main\SystemException('Network connection error: ' . $failure->message);
				\Bitrix\Main\Application::getInstance()->getExceptionHandler()->writeToLog($systemException);
			}

			$error = new TrackError($errorCode, "Chunk download failed: {$failure->message}");
		}

		$this->log && $this->logger->error("ChunkedDownloader::download: Chunk #{$chunk} failed. TrackId: {$this->track->getId()}");

		$this->sendTelemetry(
			$this->track,
			'error',
			$error->getCode() . $debugSuffix,
			$this->getEventName($this->track, 'download_chunk_failed' . $debugSuffix)
		);

		$this->chunkError = $error;
	}

	public function onChunkWritten(DownloadJob $job, int $chunk, int $bytesWritten, int $downloadedBytes): void
	{
		$log = CallAISettings::isLoggingEnable();
		$logger = Logger::getInstance();

		$log && $logger->info("ChunkedDownloader::downloadChunk: Content-Range: '{$this->contentRange}'");
		$log && $logger->info("ChunkedDownloader::downloadChunk: Success. BytesWritten: {$bytesWritten}");

		if ($downloadedBytes >= $this->fileSize)
		{
			$this->log && $this->logger->info("ChunkedDownloader::download: Completed at chunk #{$chunk}. TrackId: {$this->track->getId()}");
		}
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
		return "chunked_downloader_{$action}";
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
		$call = Registry::getCallWithId($track->getCallId());
		if (!$call)
		{
			return;
		}

		(new TrackAnalytics($call))
			->sendTelemetry(source: $track, status: $status, errorCode: $errorCode, event: $event);
	}
}

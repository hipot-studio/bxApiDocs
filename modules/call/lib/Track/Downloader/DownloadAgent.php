<?php

namespace Bitrix\Call\Track\Downloader;

use Bitrix\Call\Analytics\FollowUpAnalytics;
use Bitrix\Call\Call\Registry;
use Bitrix\Main\IO;
use Bitrix\Main\Loader;
use Bitrix\Call;
use Bitrix\Call\Track;
use Bitrix\Call\Track\TrackError;
use Bitrix\Call\Logger\Logger;
use Bitrix\Call\Integration\AI\CallAISettings;

/**
 * Unified agent for track file downloading
 *
 * @internal
 */
class DownloadAgent
{
	private const MAX_RETRY_COUNT = 10;
	private const SYNC_MAX_RETRIES = 5;
	private const NETWORK_ERROR_DELAY = 300;
	private const NETWORK_ERROR_DELAY_EXTENDED = 600;

	/**
	 * Schedule download agent for track
	 *
	 * @param int $trackId Track ID to download
	 * @param int $retryCount Current retry attempt number
	 * @param int $expectedBytes Expected downloaded bytes (for resume)
	 * @param int $syncRetry File sync retry counter
	 * @param int $delay Delay in seconds before agent runs
	 */
	public static function schedule(
		int $trackId,
		int $retryCount = 0,
		int $expectedBytes = 0,
		int $syncRetry = 0,
		int $delay = 10
	): void
	{
		$log = CallAISettings::isLoggingEnable();
		$logger = Logger::getInstance();

		if (self::hasScheduledAgent($trackId))
		{
			$log && $logger->info("DownloadAgent::schedule: Agent already exists. TrackId: {$trackId}");
			return;
		}

		$log && $logger->info("DownloadAgent::schedule: Creating agent. TrackId: {$trackId}, Delay: {$delay}s");

		$track = Call\Model\CallTrackTable::getById($trackId)->fetchObject();
		$call = Registry::getCallWithId($track->getCallId());

		(new FollowUpAnalytics($call))
			->sendTelemetry(
				source: null,
				status: 'success',
				event: 'download_agent_scheduled_' . $trackId
			);

		/* @see \Bitrix\Call\Track\Downloader\DownloadAgent::run */
		\CAgent::AddAgent(
			self::buildAgentName($trackId, $retryCount, $expectedBytes, $syncRetry),
			'call',
			'N',
			$delay,
			'',
			'Y',
			\ConvertTimeStamp(\time() + \CTimeZone::GetOffset() + $delay, 'FULL')
		);
	}

	/**
	 * Unified agent entry point
	 *
	 * @param int $trackId Track ID
	 * @param int $retryCount Retry counter (0-10)
	 * @param int $expectedBytes Expected file size (>0 means resume mode)
	 * @param int $syncRetry Sync wait counter (0-5)
	 * @param int $pauseUntil Unix timestamp until which the agent should not execute (0 = no pause)
	 * @return string Agent name for reschedule or '' to stop
	 */
	public static function run(
		int $trackId,
		int $retryCount = 0,
		int $expectedBytes = 0,
		int $syncRetry = 0,
		int $pauseUntil = 0
	): string
	{
		if (!Loader::includeModule('call'))
		{
			return '';
		}

		$log = CallAISettings::isLoggingEnable();
		$logger = Logger::getInstance();

		if ($pauseUntil > 0 && \time() < $pauseUntil)
		{
			$log && $logger->info("DownloadAgent::run: Paused until " . date('c', $pauseUntil) . ". TrackId: {$trackId}");
			return self::buildAgentName($trackId, $retryCount, $expectedBytes, $syncRetry, $pauseUntil);
		}

		$log && $logger->info(
			"DownloadAgent::run: Started. TrackId: {$trackId}, "
			. "RetryCount: {$retryCount}, ExpectedBytes: {$expectedBytes}, SyncRetry: {$syncRetry}"
		);

		$track = Call\Model\CallTrackTable::getById($trackId)->fetchObject();
		if (!$track)
		{
			$log && $logger->error("DownloadAgent::run: Track not found. TrackId: {$trackId}");
			return '';
		}

		$call = Registry::getCallWithId($track->getCallId());

		(new FollowUpAnalytics($call))
			->sendTelemetry(
				source: null,
				status: 'success',
				event: 'download_agent_started_' . $trackId
			);

		if ($track->getDownloaded() === true)
		{
			$log && $logger->info("DownloadAgent::run: Track already downloaded. TrackId: {$trackId}");

			(new FollowUpAnalytics($call))
				->sendTelemetry(
					source: null,
					status: 'success',
					event: 'download_agent_track_already_downloaded_' . $trackId
				);

			return '';
		}

		$trackId = $track->getId();
		$isResume = ($expectedBytes > 0);

		// 1. Sync check (only for resume)
		if ($isResume)
		{
			$syncResult = self::checkFileSync($track, $expectedBytes, $syncRetry);
			if ($syncResult !== null)
			{
				(new FollowUpAnalytics($call))
					->sendTelemetry(
						source: null,
						status: 'success',
						event: 'download_agent_wait_for_sync_' . $trackId
					);
				return $syncResult;
			}
		}

		// 2. Download directly
		$log && $logger->info("DownloadAgent::run: Starting download. TrackId: {$trackId}");

		$downloader = DownloaderFactory::create($track);
		$result = $downloader->download($track);

		// 3. Handle result — return agent name, Bitrix will update the agent
		$status = $result->getData()['status'] ?? null;
		if ($status === 'in_progress')
		{
			$downloadedBytes = $result->getData()['downloaded_bytes'] ?? 0;
			$log && $logger->info("DownloadAgent::run: In progress, {$downloadedBytes} bytes. TrackId: {$trackId}");

			(new FollowUpAnalytics($call))
				->sendTelemetry(
					source: null,
					status: 'success',
					event: 'download_agent_in_progress_' . $trackId
				);

			return self::buildAgentName($trackId, 0, $downloadedBytes, 0, 0);
		}

		if ($result->isSuccess())
		{
			$log && $logger->info("DownloadAgent::run: Success. TrackId: {$trackId}");

			(new FollowUpAnalytics($call))
				->sendTelemetry(
					source: null,
					status: 'success',
					event: 'download_agent_finished_success_' . $trackId
				);

			return '';
		}

		// 4. Error → retry via return value
		$log && $logger->error(
			"DownloadAgent::run: Failed. TrackId: {$trackId}. "
			. "Errors: " . implode('; ', $result->getErrorMessages())
		);

		if ($retryCount <= self::MAX_RETRY_COUNT)
		{
			$nextRetry = $retryCount + 1;

			$isNetworkError = false;
			foreach ($result->getErrors() as $error)
			{
				if ($error->getCode() === TrackError::NETWORK_ERROR)
				{
					$isNetworkError = true;
					break;
				}
			}

			if ($isNetworkError)
			{
				$delay = $nextRetry > 2 ? self::NETWORK_ERROR_DELAY_EXTENDED : self::NETWORK_ERROR_DELAY;
				$pauseUntil = time() + $delay;

				$log && $logger->info("DownloadAgent::run: Network error, pausing for {$delay}s until " . date('c', $pauseUntil) . ". TrackId: {$trackId}");
				(new FollowUpAnalytics($call))
					->sendTelemetry(
						source: null,
						status: 'error',
						event: 'download_agent_retried_on_network_error_' . $trackId,
						error: $result->getError()
					);
			}
			else
			{
				$pauseUntil = 0;

				$log && $logger->info("DownloadAgent::run: Retry #{$nextRetry}. TrackId: {$trackId}");
				(new FollowUpAnalytics($call))
					->sendTelemetry(
						source: null,
						status: 'error',
						event: 'download_agent_retried_on_error_' . $trackId,
						error: $result->getError()
					);
			}

			return self::buildAgentName($trackId, $nextRetry, 0, 0, $pauseUntil);
		}

		(new FollowUpAnalytics($call))
			->sendTelemetry(
				source: null,
				status: 'error',
				event: 'download_agent_finished_with_max_retries_' . $trackId,
				error: $result->getError()
			);

		$log && $logger->error("DownloadAgent::run: Max retries reached. TrackId: {$trackId}");
		return '';
	}

	/**
	 * Check file synchronization for resume
	 *
	 * @return string|null Agent name to reschedule, or null to continue
	 */
	private static function checkFileSync(Track $track, int $expectedBytes, int $syncRetry): ?string
	{
		$log = CallAISettings::isLoggingEnable();
		$logger = Logger::getInstance();
		$trackId = $track->getId();

		$tempPath = $track->getTempPath();
		$actualBytes = 0;

		if (!empty($tempPath))
		{
			\clearstatcache(true, $tempPath);
			$file = new IO\File($tempPath);
			$actualBytes = $file->isExists() ? (int)$file->getSize() : 0;
		}

		$log && $logger->info(
			"DownloadAgent::checkFileSync: Expected: {$expectedBytes}, Actual: {$actualBytes}. TrackId: {$trackId}"
		);

		if ($actualBytes >= $expectedBytes)
		{
			return null; // File synced, continue
		}

		if ($syncRetry < self::SYNC_MAX_RETRIES)
		{
			$nextSyncRetry = $syncRetry + 1;
			$log && $logger->info(
				"DownloadAgent::checkFileSync: File not synced. "
				. "Retry {$nextSyncRetry}/" . self::SYNC_MAX_RETRIES . ". TrackId: {$trackId}"
			);

			return self::buildAgentName($trackId, 0, $expectedBytes, $nextSyncRetry, 0);
		}

		// Max sync retries — delete and restart
		$log && $logger->warning(
			"DownloadAgent::checkFileSync: Max sync retries reached. Restarting from scratch. TrackId: {$trackId}"
		);

		self::deleteTempFile($track);
		$track->generateTemporaryPath()->save();

		return null; // Continue with fresh start
	}

	/**
	 * Build agent name string
	 */
	private static function buildAgentName(
		int $trackId,
		int $retryCount,
		int $expectedBytes,
		int $syncRetry,
		int $pauseUntil = 0
	): string
	{
		return self::class . "::run({$trackId}, {$retryCount}, {$expectedBytes}, {$syncRetry}, {$pauseUntil});";
	}

	/**
	 * Check if agent already scheduled for this track
	 */
	private static function hasScheduledAgent(int $trackId): bool
	{
		$pattern = self::class . "::run({$trackId},%";

		$agents = \CAgent::getList([], [
			'MODULE_ID' => 'call',
			'NAME' => $pattern,
		]);

		return (bool)$agents->fetch();
	}

	/**
	 * Delete temp file for track
	 */
	private static function deleteTempFile(Track $track): void
	{
		$tempPath = $track->getTempPath();
		if ($tempPath)
		{
			$file = new IO\File($tempPath);
			if ($file->isExists())
			{
				$file->delete();
			}
		}
	}
}

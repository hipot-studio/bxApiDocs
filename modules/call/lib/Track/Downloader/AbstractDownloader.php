<?php

namespace Bitrix\Call\Track\Downloader;

use Bitrix\Main\Result;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Call\Track;
use Bitrix\Call\Track\TrackError;
use Bitrix\Call\Track\TrackService;
use Bitrix\Call\Logger\Logger;
use Bitrix\Call\Integration\AI\CallAISettings;

/**
 * Base class for track file downloaders
 *
 * @internal
 */
abstract class AbstractDownloader
{
	/**
	 * Download file to temp path
	 *
	 * @param Track $track Track entity
	 * @return Result with ['status' => 'completed'|'in_progress'|'error']
	 */
	abstract public function download(Track $track): Result;

	/**
	 * Mark download as completed and fire completion event
	 *
	 * @param Result $result
	 * @param Track $track
	 * @return Result
	 */
	protected function complete(Result $result, Track $track): Result
	{
		$log = CallAISettings::isLoggingEnable();
		$logger = Logger::getInstance();

		$validateResult = DownloadHelper::validateFile($track);
		if (!$validateResult->isSuccess())
		{
			$log && $logger->error("AbstractDownloader::complete: Validation failed. TrackId: {$track->getId()}");
			$result->addErrors($validateResult->getErrors());

			return $this->fail($result);
		}

		$log && $logger->info("AbstractDownloader::complete: Firing event. TrackId: {$track->getId()}");

		// Fire event instead of calling callback. The handler (TrackService::finalizeDownload)
		// mutates this same $track instance, so its result is visible right after send().
		$event = new Event('call', 'onCallTrackDownloadCompleted', ['track' => $track]);
		$event->send();

		// The finalize handler is the source of truth: a record may have file+disk set yet still
		// not be finalized (e.g. its chat link failed to publish). Honor its verdict so the agent
		// retries, then fall back to the persisted-state check.
		foreach ($event->getResults() as $eventResult)
		{
			if ($eventResult->getType() === EventResult::ERROR)
			{
				$log && $logger->error("AbstractDownloader::complete: Finalization reported failure. TrackId: {$track->getId()}");
				$result->addError(new TrackError(TrackError::FINALIZE_FAILED, 'Track finalization failed'));

				return $this->fail($result);
			}
		}

		// Do not report success unless the track was actually finalized (attached to file/disk).
		// Otherwise DownloadAgent would emit finished_success and stop, silently losing the track.
		if (!$this->isFinalized($track))
		{
			$log && $logger->error("AbstractDownloader::complete: Finalization failed. TrackId: {$track->getId()}");
			$result->addError(new TrackError(TrackError::FINALIZE_FAILED, 'Track finalization failed'));

			return $this->fail($result);
		}

		return $result->setData(['status' => 'completed']);
	}

	/**
	 * Whether the track has been fully finalized: file attached, and disk attached when required.
	 */
	private function isFinalized(Track $track): bool
	{
		if (!$track->getFileId())
		{
			return false;
		}

		if (TrackService::getInstance()->requiresDisk($track) && !$track->getDiskFileId())
		{
			return false;
		}

		return true;
	}

	/**
	 * Mark download as in progress (will continue via agent)
	 */
	protected function progress(Result $result, int $downloadedBytes = 0): Result
	{
		return $result->setData([
			'status' => 'in_progress',
			'downloaded_bytes' => $downloadedBytes,
		]);
	}

	/**
	 * Mark download as failed
	 */
	protected function fail(Result $result): Result
	{
		return $result->setData(['status' => 'error']);
	}
}

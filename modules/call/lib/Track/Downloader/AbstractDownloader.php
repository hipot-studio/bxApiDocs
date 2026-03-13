<?php

namespace Bitrix\Call\Track\Downloader;

use Bitrix\Main\Result;
use Bitrix\Main\Event;
use Bitrix\Call\Track;
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

		$log && $logger->info("AbstractDownloader::complete: Firing event. TrackId: {$track->getId()}");

		// Fire event instead of calling callback
		$event = new Event('call', 'onCallTrackDownloadCompleted', ['track' => $track]);
		$event->send();

		return $result->setData(['status' => 'completed']);
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

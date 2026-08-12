<?php

namespace Bitrix\Call\Track\Downloader;

use Bitrix\Call\Track;
use Bitrix\Call\Logger\Logger;
use Bitrix\Call\Integration\AI\CallAISettings;
use Bitrix\Call\Analytics\TrackAnalytics;
use Bitrix\Call\Call\Registry;

/**
 * Factory for creating appropriate downloader based on server capabilities
 *
 * @internal
 */
class DownloaderFactory
{
	/**
	 * Create appropriate downloader based on server capabilities
	 */
	public static function create(Track $track): AbstractDownloader
	{
		$log = CallAISettings::isLoggingEnable();
		$logger = Logger::getInstance();

		$rangeCheck = DownloadHelper::checkRangeSupport($track->getDownloadUrl());

		$useChunked = $rangeCheck['supports_range'] && $rangeCheck['file_size'] > 0;
		self::sendSelectionTelemetry($track, $rangeCheck, $useChunked);

		if ($useChunked)
		{
			$track->setFileSize($rangeCheck['file_size']);
			$log && $logger->info("DownloaderFactory::create: ChunkedDownloader. TrackId: {$track->getId()}, FileSize: {$rangeCheck['file_size']}");

			return new ChunkedDownloader($rangeCheck['file_size']);
		}

		$log && $logger->info("DownloaderFactory::create: FullDownloader. TrackId: {$track->getId()}");

		return new FullDownloader();
	}

	/**
	 * Report which downloader was chosen and why (received-record size/headers).
	 */
	private static function sendSelectionTelemetry(Track $track, array $rangeCheck, bool $useChunked): void
	{
		$call = Registry::getCallWithId($track->getCallId());
		if (!$call)
		{
			return;
		}

		// A failed HEAD is not a selection error: the factory simply falls back to FullDownloader.
		// Report it as success and expose the HEAD error via context so the event explains the
		// chosen path instead of looking like a failure.
		(new TrackAnalytics($call))->sendTelemetry(
			source: $track,
			status: 'success',
			errorCode: null,
			event: 'downloader_selected',
			context: [
				'downloader' => $useChunked ? 'chunked' : 'full',
				'supportsRange' => $rangeCheck['supports_range'],
				'contentLength' => $rangeCheck['file_size'],
				'contentType' => $rangeCheck['content_type'] ?? '',
				'headError' => $rangeCheck['error'] ?? '',
			],
		);
	}
}

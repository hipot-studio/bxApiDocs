<?php

namespace Bitrix\Call\Track\Downloader;

use Bitrix\Call\Track;
use Bitrix\Call\Logger\Logger;
use Bitrix\Call\Integration\AI\CallAISettings;

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

		if ($rangeCheck['supports_range'] && $rangeCheck['file_size'] > 0)
		{
			$track->setFileSize($rangeCheck['file_size']);
			$log && $logger->info("DownloaderFactory::create: ChunkedDownloader. TrackId: {$track->getId()}, FileSize: {$rangeCheck['file_size']}");

			return new ChunkedDownloader($rangeCheck['file_size']);
		}

		$log && $logger->info("DownloaderFactory::create: FullDownloader. TrackId: {$track->getId()}");

		return new FullDownloader();
	}
}

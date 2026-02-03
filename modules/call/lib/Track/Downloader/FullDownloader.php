<?php

namespace Bitrix\Call\Track\Downloader;

use Bitrix\Main\Result;
use Bitrix\Call\Track;
use Bitrix\Call\Track\TrackError;
use Bitrix\Call\Logger\Logger;
use Bitrix\Call\Integration\AI\CallAISettings;

/**
 * Downloads track file completely in one request (no chunking)
 */
class FullDownloader extends AbstractDownloader
{
	/**
	 * @inheritDoc
	 */
	public function download(Track $track): Result
	{
		$result = new Result();
		$log = CallAISettings::isLoggingEnable();
		$logger = Logger::getInstance();

		$tempPath = $track->generateTemporaryPath()->getTempPath();
		$track->save();

		$log && $logger->info("FullDownloader::download: Starting. TrackId: {$track->getId()}, Url: {$track->getDownloadUrl()}");

		$httpClient = DownloadHelper::createHttpClient();
		$isDownloadSuccess = $httpClient->download($track->getDownloadUrl(), $tempPath);

		if (!$isDownloadSuccess || $httpClient->getStatus() !== 200)
		{
			$errors = [];
			foreach ($httpClient->getError() as $code => $message)
			{
				$errors[] = $code . ': ' . $message;
			}
			$errorMessage = !empty($errors) ? implode('; ', $errors) : 'HTTP ' . $httpClient->getStatus();

			$log && $logger->error("FullDownloader::download: Failed. Error: {$errorMessage}. TrackId: {$track->getId()}");

			return $result->addError(new TrackError(TrackError::DOWNLOAD_ERROR, "Download failed: {$errorMessage}"));
		}

		$log && $logger->info("FullDownloader::download: Completed. TempPath: {$tempPath}. TrackId: {$track->getId()}");

		return $this->complete($result, $track);
	}
}

<?php

namespace Bitrix\Call\Track\Downloader;

use Bitrix\Main\Result;
use Bitrix\Main\Loader;
use Bitrix\Call\Track;
use Bitrix\Call\Track\TrackError;
use Bitrix\Call\Logger\Logger;
use Bitrix\Call\Integration\AI\CallAISettings;
use Bitrix\Call\Analytics\FollowUpAnalytics;
use Bitrix\Call\Call\Registry;

/**
 * Downloads track file completely in one request (no chunking)
 *
 * @internal
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

		// Send telemetry about download start
		$this->sendTelemetry($track, 'success', null, $this->getEventName($track, 'download_started'));

		$httpClient = DownloadHelper::createHttpClient();
		$isDownloadSuccess = $httpClient->download($track->getDownloadUrl(), $tempPath);

		if (!$isDownloadSuccess || $httpClient->getStatus() !== 200)
		{
			$httpErrors = $httpClient->getError();
			$isNetworkError = isset($httpErrors['NETWORK']);

			$errors = array_values($httpErrors);
			$status = $httpClient->getStatus();
			if ($status != 200)
			{
				$errors[] = "Expected HTTP 200, got: {$status}";
			}
			$errors[] = 'url: ' . (parse_url($track->getDownloadUrl(), PHP_URL_HOST) ?: $track->getDownloadUrl());

			$errorMessage = implode('; ', $errors);

			$log && $logger->error("FullDownloader::download: Failed. Error: {$errorMessage}. TrackId: {$track->getId()}");

			if ($isNetworkError)
			{
				$systemException = new \Bitrix\Main\SystemException('Network connection error: ' . $errorMessage);
				\Bitrix\Main\Application::getInstance()->getExceptionHandler()->writeToLog($systemException);
			}

			// Send telemetry about download error
			$errorCode = $httpClient->getStatus() ?: 'download_error';
			$this->sendTelemetry($track, 'error', (string)$errorCode, $this->getEventName($track, 'download_failed'));

			return $result->addError(new TrackError(
				$isNetworkError ? TrackError::NETWORK_ERROR : TrackError::DOWNLOAD_ERROR,
				"Download failed: {$errorMessage}"
			));
		}

		$log && $logger->info("FullDownloader::download: Completed. TempPath: {$tempPath}. TrackId: {$track->getId()}");

		// Send telemetry about successful download completion
		$this->sendTelemetry($track, 'success', null, $this->getEventName($track, 'download_completed'));

		return $this->complete($result, $track);
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
		return "full_downloader_{$action}_{$track->getId()}";
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

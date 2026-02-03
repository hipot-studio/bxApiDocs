<?php

namespace Bitrix\Call\Track\Downloader;

use Bitrix\Main\IO;
use Bitrix\Main\Result;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Call\Track;
use Bitrix\Call\Track\TrackError;
use Bitrix\Call\Logger\Logger;
use Bitrix\Call\Integration\AI\CallAISettings;

/**
 * Helper utilities for track file downloading
 */
class DownloadHelper
{
	/**
	 * Check if remote server supports Range requests and get file size.
	 *
	 * @param string $url Download URL
	 * @return array{supports_range: bool, file_size: int, error: ?string}
	 */
	public static function checkRangeSupport(string $url): array
	{
		$log = CallAISettings::isLoggingEnable();
		$logger = Logger::getInstance();

		$result = [
			'supports_range' => false,
			'file_size' => 0,
			'error' => null,
		];

		$log && $logger->info("DownloadHelper::checkRangeSupport: HEAD request to {$url}");

		$httpClient = self::createHttpClient();
		$httpClient->head($url);

		$status = $httpClient->getStatus();
		if ($status !== 200)
		{
			$result['error'] = 'HEAD request failed with status: ' . $status;
			$log && $logger->error("DownloadHelper::checkRangeSupport: HEAD failed. Status: {$status}");
			return $result;
		}

		$headers = $httpClient->getHeaders();
		$result['file_size'] = (int)$headers->get('Content-Length');
		$acceptRanges = $headers->get('Accept-Ranges') ?? '';
		$result['supports_range'] = (mb_strtolower($acceptRanges) === 'bytes');

		$log && $logger->info(
			"DownloadHelper::checkRangeSupport: FileSize: {$result['file_size']}, "
			. "Accept-Ranges: '{$acceptRanges}', SupportsRange: " . ($result['supports_range'] ? 'yes' : 'no')
		);

		return $result;
	}

	/**
	 * Validate downloaded file size.
	 *
	 * @param Track $track Track entity with temp file
	 * @return Result Success if file is valid, error otherwise
	 */
	public static function validateFile(Track $track): Result
	{
		$result = new Result();
		$log = CallAISettings::isLoggingEnable();
		$logger = Logger::getInstance();

		$tempPath = $track->getTempPath();
		$file = new IO\File($tempPath);

		if (!$file->isExists())
		{
			$log && $logger->error("DownloadHelper::validateFile: File not found. TrackId: {$track->getId()}");
			return $result->addError(new TrackError(TrackError::FILE_SIZE_ZERO, 'Downloaded file not found'));
		}

		$actualSize = (int)$file->getSize();

		$log && $logger->info(
			"DownloadHelper::validateFile: TempPath: {$tempPath}, ActualSize: {$actualSize}, "
			. "ExpectedSize: {$track->getFileSize()}. TrackId: {$track->getId()}"
		);

		if ($actualSize === 0)
		{
			$log && $logger->error("DownloadHelper::validateFile: File is empty. TrackId: {$track->getId()}");
			$file->delete();
			return $result->addError(new TrackError(TrackError::FILE_SIZE_ZERO, 'Downloaded file is empty'));
		}

		if ($track->getFileSize() > 0 && $actualSize !== $track->getFileSize())
		{
			$log && $logger->error(
				"DownloadHelper::validateFile: Size mismatch. Expected: {$track->getFileSize()}, "
				. "got: {$actualSize}. TrackId: {$track->getId()}"
			);
			$file->delete();
			return $result->addError(new TrackError(
				TrackError::FILE_SIZE_MISMATCH,
				"File size mismatch: expected {$track->getFileSize()}, got {$actualSize}"
			));
		}

		$log && $logger->info("DownloadHelper::validateFile: OK. TrackId: {$track->getId()}");

		return $result;
	}

	/**
	 * Create configured HttpClient instance
	 *
	 * @return HttpClient
	 */
	public static function createHttpClient(): HttpClient
	{
		$httpClient = new HttpClient();
		$httpClient
			->waitResponse(true)
			->setTimeout(20)
			->setStreamTimeout(60)
			->disableSslVerification()
			->setHeader('User-Agent', 'Bitrix Call Client ' . \Bitrix\Main\Service\MicroService\Client::getPortalType())
			->setHeader('Referer', \Bitrix\Main\Service\MicroService\Client::getServerName())
		;

		return $httpClient;
	}
}

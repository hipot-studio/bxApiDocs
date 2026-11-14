<?php

namespace Bitrix\Call\Track\Downloader;

use Bitrix\Main\IO;
use Bitrix\Main\Result;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Call\Download\DownloadPolicy;
use Bitrix\Call\Track;
use Bitrix\Call\Track\TrackError;
use Bitrix\Call\Logger\Logger;
use Bitrix\Call\Integration\AI\CallAISettings;

/**
 * Helper utilities for track file downloading
 *
 * @internal
 */
class DownloadHelper
{
	/**
	 * Check if remote server supports Range requests and get file size.
	 *
	 * The timeouts are those of the download by default, which takes this HEAD before it chooses
	 * how to fetch the file {@see DownloaderFactory::create()}. A caller that asks the address on
	 * behalf of somebody waiting for the answer passes shorter ones of its own
	 * {@see \Bitrix\Call\Track\TrackService::DELIVERY_PROBE_CONNECT_TIMEOUT},
	 * {@see \Bitrix\Call\Track\TrackService::DELIVERY_PROBE_READ_TIMEOUT}.
	 *
	 * @param string $url Download URL
	 * @param int $connectTimeout seconds to wait for the connection
	 * @param int $streamTimeout seconds to wait for the answer once connected
	 * @return array{supports_range: bool, file_size: int, content_type: string, status: int,
	 *     error: ?string} `status` is the http status the HEAD was answered with, and zero where
	 *     it was not answered at all - the caller that refuses a replacement by this probe tells
	 *     "the file is not there" from "the address could not be asked" by it
	 *     {@see \Bitrix\Call\Track\TrackService::replaceLocalCopy()}
	 */
	public static function checkRangeSupport(
		string $url,
		int $connectTimeout = DownloadPolicy::DEFAULT_CONNECT_TIMEOUT,
		int $streamTimeout = DownloadPolicy::DEFAULT_STREAM_TIMEOUT
	): array
	{
		$log = CallAISettings::isLoggingEnable();
		$logger = Logger::getInstance();

		$result = [
			'supports_range' => false,
			'file_size' => 0,
			'content_type' => '',
			'status' => 0,
			'error' => null,
		];

		$log && $logger->info("DownloadHelper::checkRangeSupport: HEAD request to {$url}");

		$httpClient = self::createHttpClient($connectTimeout, $streamTimeout);
		$httpClient->head($url);

		$status = (int)$httpClient->getStatus();
		$result['status'] = $status;
		if ($status !== 200)
		{
			$result['error'] = 'HEAD request failed with status: ' . $status;
			$log && $logger->error("DownloadHelper::checkRangeSupport: HEAD failed. Status: {$status}");
			return $result;
		}

		$headers = $httpClient->getHeaders();
		$result['file_size'] = (int)$headers->get('Content-Length');
		$result['content_type'] = (string)($headers->get('Content-Type') ?? '');
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
	 * Create configured HttpClient instance: the client of the track policy, which the chunks of
	 * {@see ChunkedDownloader} are requested with as well. Without arguments it is exactly that
	 * client; other timeouts replace only the timeouts of the policy {@see DownloadPolicy::withTimeouts()}.
	 *
	 * @param int $connectTimeout seconds to wait for the connection
	 * @param int $streamTimeout seconds a response may stay silent (CURLOPT_LOW_SPEED_TIME)
	 * @return HttpClient
	 */
	public static function createHttpClient(
		int $connectTimeout = DownloadPolicy::DEFAULT_CONNECT_TIMEOUT,
		int $streamTimeout = DownloadPolicy::DEFAULT_STREAM_TIMEOUT
	): HttpClient
	{
		$policy = DownloadPolicy::forTracks();
		if ($connectTimeout !== $policy->connectTimeout || $streamTimeout !== $policy->streamTimeout)
		{
			$policy = $policy->withTimeouts($connectTimeout, $streamTimeout);
		}

		return $policy->createHttpClient();
	}
}

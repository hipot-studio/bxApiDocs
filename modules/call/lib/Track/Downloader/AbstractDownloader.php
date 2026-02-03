<?php

namespace Bitrix\Call\Track\Downloader;

use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Call;
use Bitrix\Call\Track;
use Bitrix\Call\Track\TrackService;
use Bitrix\Call\Logger\Logger;
use Bitrix\Call\Integration\AI\CallAISettings;

/**
 * Base class for track file downloaders with common retry logic
 */
abstract class AbstractDownloader
{
	protected const MAX_RETRY_COUNT = 3;
	protected const RETRY_DELAY = 10;

	/** @var callable|null */
	protected $onCompleteCallback;

	/**
	 * Download file to temp path
	 *
	 * @param Track $track Track entity
	 * @return Result with ['status' => 'completed'|'in_progress'|'error']
	 */
	abstract public function download(Track $track): Result;

	/**
	 * Set callback to be called when download completes successfully
	 *
	 * @param callable $callback Function that accepts Track as parameter
	 * @return self
	 */
	public function setOnComplete(callable $callback): self
	{
		$this->onCompleteCallback = $callback;
		return $this;
	}

	/**
	 * Call the onComplete callback if set
	 *
	 * @param Track $track
	 */
	protected function onComplete(Track $track): void
	{
		if ($this->onCompleteCallback)
		{
			($this->onCompleteCallback)($track);
		}
	}

	/**
	 * Mark download as completed and call onComplete callback
	 */
	protected function complete(Result $result, Track $track): Result
	{
		$this->onComplete($track);
		return $result->setData(['status' => 'completed']);
	}

	/**
	 * Mark download as in progress (will continue via agent)
	 */
	protected function progress(Result $result): Result
	{
		return $result->setData(['status' => 'in_progress']);
	}

	/**
	 * Mark download as failed
	 */
	protected function fail(Result $result): Result
	{
		return $result->setData(['status' => 'error']);
	}

	/**
	 * Check if retry agent already exists for given track
	 *
	 * @param int $trackId
	 * @return bool
	 */
	protected static function hasScheduledRetryAgent(int $trackId): bool
	{
		$patterns = [
			static::class . "::retryAgent({$trackId});",   // initial: retryAgent(123);
			static::class . "::retryAgent({$trackId}, %",  // retry:   retryAgent(123, 2);
		];

		$result = false;
		foreach ($patterns as $pattern)
		{
			$agents = \CAgent::getList([], [
				'MODULE_ID' => 'call',
				'NAME' => $pattern,
			]);

			if ($agents->fetch())
			{
				$result = true;
				break;
			}
		}

		return $result;
	}

	/**
	 * Schedule retry agent on failure
	 *
	 * @param int $trackId
	 * @param int $delay Delay in seconds before retry
	 */
	public static function scheduleRetry(int $trackId, int $delay = self::RETRY_DELAY): void
	{
		$log = CallAISettings::isLoggingEnable();
		$logger = Logger::getInstance();

		if (static::hasScheduledRetryAgent($trackId))
		{
			$log && $logger->info("AbstractDownloader::scheduleRetry: Agent already exists. TrackId: {$trackId}");
			return;
		}

		$log && $logger->info("AbstractDownloader::scheduleRetry: Creating agent. TrackId: {$trackId}, Delay: {$delay}s");

		/** @see self::retryAgent() */
		\CAgent::AddAgent(
			static::class . "::retryAgent({$trackId});",
			'call',
			'N',
			60,
			'',
			'Y',
			\ConvertTimeStamp(\time() + \CTimeZone::GetOffset() + $delay, 'FULL')
		);
	}

	/**
	 * Agent for retrying failed downloads.
	 * Uses TrackService::downloadTrackFile which will choose appropriate downloader.
	 *
	 * @param int $trackId
	 * @param int $retryCount Current retry attempt
	 * @return string Empty string to stop, or agent call to continue
	 */
	public static function retryAgent(int $trackId, int $retryCount = 1): string
	{
		if (!Loader::includeModule('call'))
		{
			return '';
		}

		$log = CallAISettings::isLoggingEnable();
		$logger = Logger::getInstance();

		$log && $logger->info("AbstractDownloader::retryAgent: Started. TrackId: {$trackId}, Retry: {$retryCount}");

		$track = Call\Model\CallTrackTable::getById($trackId)->fetchObject();
		if (!$track)
		{
			$log && $logger->error("AbstractDownloader::retryAgent: Track not found. TrackId: {$trackId}");
			return '';
		}

		if ($track->getDownloaded() === true)
		{
			$log && $logger->info("AbstractDownloader::retryAgent: Track already downloaded. TrackId: {$trackId}");
			return '';
		}

		$trackService = TrackService::getInstance();
		$result = $trackService->downloadTrackFile($track, false);

		// Check if in_progress (chunked download took over)
		$resultData = $result->getData();
		if (isset($resultData['status']) && $resultData['status'] === 'in_progress')
		{
			$log && $logger->info("AbstractDownloader::retryAgent: Chunked download in progress. TrackId: {$trackId}");
			return '';
		}

		if ($result->isSuccess())
		{
			$log && $logger->info("AbstractDownloader::retryAgent: Success. TrackId: {$trackId}");
			return '';
		}

		if ($retryCount >= self::MAX_RETRY_COUNT)
		{
			$log && $logger->error("AbstractDownloader::retryAgent: Max retries reached. TrackId: {$trackId}");
			return '';
		}

		$retryCount++;
		$log && $logger->info("AbstractDownloader::retryAgent: Scheduling retry #{$retryCount}. TrackId: {$trackId}");

		return __METHOD__ . "({$trackId}, {$retryCount});";
	}
}

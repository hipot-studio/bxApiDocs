<?php

namespace Bitrix\Call\Track;

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Im\Call\Registry;
use Bitrix\Call;
use Bitrix\Call\NotifyService;
use Bitrix\Call\Logger\Logger;
use Bitrix\Call\Integration\AI;
use Bitrix\Call\Integration\AI\CallAIError;
use Bitrix\Call\Integration\AI\CallAISettings;
use Bitrix\Call\Analytics\FollowUpAnalytics;
use Bitrix\Call\Track\Downloader\DownloadHelper;
use Bitrix\Call\Track\Downloader\AbstractDownloader;
use Bitrix\Call\Track\Downloader\FullDownloader;
use Bitrix\Call\Track\Downloader\ChunkedDownloader;


final class TrackService
{
	private static ?TrackService $service = null;

	private function __construct()
	{}

	public static function getInstance(): self
	{
		if (!self::$service)
		{
			self::$service = new self();
		}
		return self::$service;
	}

	public function doNeedDownloadTrack(Call\Track $track): bool
	{
		return
			!$track->getDownloaded()
			&& !empty($track->getDownloadUrl())
		;
	}

	public function doNeedNeedAttachToDisk(Call\Track $track): bool
	{
		if ($track->getType() != Call\Track::TYPE_RECORD)
		{
			return false;
		}

		return
			!$track->getDiskFileId()
			&& $track->getFileId()
		;
	}

	public function doNeedNeedAiProcessing(Call\Track $track): bool
	{
		if ($track->getType() != Call\Track::TYPE_TRACK_PACK)
		{
			return false;
		}

		$minDuration = \Bitrix\Call\Integration\AI\CallAISettings::getRecordMinDuration();
		if ($track->getDuration() > 0 && $track->getDuration() < $minDuration)
		{
			return false;
		}

		$taskList = Call\Model\CallAITaskTable::query()
			->setSelect(['ID'])
			->where('TRACK_ID', $track->getId())
			->setLimit(1)
			->exec()
		;

		return $taskList->getSelectedRowsCount() == 0;
	}

	public function processTrack(Call\Track $track): Result
	{
		$result = new Result();

		if ($log = \Bitrix\Call\Integration\AI\CallAISettings::isLoggingEnable())
		{
			$logger = Logger::getInstance();
			$logger->info(
				"Call track file: {$track->getFileName()}"
				. ", size:{$track->getFileSize()}b"
				. ", type:{$track->getType()}"
				. ", duration:{$track->getDuration()}"
				. ", source:{$track->getDownloadUrl()}"
				. ", url:{$track->getUrl()}"
			);
		}

		$event = $this->fireTrackReadyEvent($track);
		if (
			($eventResult = $event->getResults()[0] ?? null)
			&& $eventResult instanceof EventResult
			&& $eventResult->getType() == EventResult::ERROR
		)
		{
			$log && $logger->info('Processing track was canceled by event. TrackId:'.$track->getId());

			return $result;// cancel processing by event
		}

		if (in_array($track->getType(), [Call\Track::TYPE_VIDEO_RECORD, Call\Track::TYPE_VIDEO_PREVIEW], true))
		{
			return $this->processCloudVideoTrack($track, $result);
		}

		return $this->processAiTrack($track, $result);
	}

	protected function processCloudVideoTrack(Call\Track $track, Result $result): Result
	{
		if ($log = CallAISettings::isLoggingEnable())
		{
			$logger = Logger::getInstance();
		}

		$log && $logger->info("Processing cloud video track. TrackId: {$track->getId()}, Type: {$track->getType()}");

		if (!Loader::includeModule('im'))
		{
			$log && $logger->error("IM module not loaded. TrackId: {$track->getId()}");
			return $result;
		}

		$callId = $track->getCallId();

		$tracks = Call\Model\CallTrackTable::query()
			->setSelect(['*'])
			->where('CALL_ID', $callId)
			->whereIn('TYPE', [Call\Track::TYPE_VIDEO_RECORD, Call\Track::TYPE_VIDEO_PREVIEW])
			->exec()
			->fetchCollection();

		$record = null;
		$preview = null;

		foreach ($tracks as $trackObj)
		{
			if ($trackObj->getType() === Call\Track::TYPE_VIDEO_RECORD && $trackObj->getDownloaded())
			{
				$record = $trackObj;
			}
			elseif ($trackObj->getType() === Call\Track::TYPE_VIDEO_PREVIEW && $trackObj->getDownloaded())
			{
				$preview = $trackObj;
			}
		}

		if (!$record || !$preview)
		{
			$log && $logger->info("Waiting for both tracks. CallId: {$callId}, Record: " . ($record ? 'yes' : 'no') . ", Preview: " . ($preview ? 'yes' : 'no'));
			return $result;
		}

		if (!$record->getFileId() || !$preview->getFileId())
		{
			$log && $logger->error("Missing file IDs. RecordFileId: {$record->getFileId()}, PreviewFileId: {$preview->getFileId()}");
			return $result;
		}

		$log && $logger->info("Attaching preview to record. RecordFileId: {$record->getFileId()}, PreviewFileId: {$preview->getFileId()}");

		(new \Bitrix\Main\UI\Viewer\PreviewManager())->setPreviewImageId(
			$record->getFileId(),
			$preview->getFileId()
		);

		$call = Registry::getCallWithId($callId);
		if ($call)
		{
			$log && $logger->info("Sending recording ready message. CallId: {$callId}, RecordId: {$record->getId()}");
			NotifyService::getInstance()->sendRecordingReadyMessage($call, $record);
		}
		else
		{
			$log && $logger->error("Call not found. CallId: {$callId}");
		}

		return $result;
	}

	protected function processAiTrack(Call\Track $track, Result $result): Result
	{
		if ($log = CallAISettings::isLoggingEnable())
		{
			$logger = Logger::getInstance();
		}

		$minDuration = CallAISettings::getRecordMinDuration();
		if ($track->getDuration() > 0 && $track->getDuration() < $minDuration)
		{
			$log && $logger->error("Ignoring track:{$track->getUrl()}, track #{$track->getExternalTrackId()}. Call #{$track->getCallId()} was too short.");

			$error = new CallAIError(CallAIError::AI_RECORD_TOO_SHORT);


			$aiService = AI\CallAIService::getInstance();
			$aiService->removeExpectation($track->getCallId());

			if ($track->getType() == Call\Track::TYPE_TRACK_PACK)
			{
				$call = Registry::getCallWithId($track->getCallId());
				NotifyService::getInstance()->sendTaskFailedMessage($error, $call);

				(new FollowUpAnalytics($call))->addGotEmptyRecord();
			}

			return $result->addError($error);
		}

		if ($this->doNeedNeedAiProcessing($track))
		{
			if (!CallAISettings::isCallAIEnable())
			{
				$log && $logger->error('Unable process track. Module AI is unavailable. TrackId:'.$track->getId());

				$error = new CallAIError(CallAIError::AI_UNAVAILABLE_ERROR);
				$error->allowRecover();

				$call = Registry::getCallWithId($track->getCallId());
				NotifyService::getInstance()->sendTaskFailedMessage($error, $call);

				return $result->addError($error);
			}

			//todo: Unable process track if it is not enough baas packages. Throw AI_NOT_ENOUGH_BAAS_ERROR

			$log && $logger->info('Start AI processing. TrackId:'.$track->getId());

			$aiService = AI\CallAIService::getInstance();
			$aiResult = $aiService->processTrack($track);
			if (!$aiResult->isSuccess())
			{
				$error = $aiResult->getError();

				$call = Registry::getCallWithId($track->getCallId());
				NotifyService::getInstance()->sendTaskFailedMessage($error, $call);

				$result->addErrors($aiResult->getErrors());
			}
		}

		return $result;
	}

	/**
	 * Create callback for post-download processing.
	 * Used by downloaders to finalize download after completion.
	 *
	 * @return callable
	 */
	public function onDownloadCompleteCallback(): callable
	{
		return function (Call\Track $track): void
		{
			$this->finalizeDownload($track);
		};
	}

	/**
	 * Finalize download: validate, attach to storage, fire events, process track.
	 *
	 * @param Call\Track $track
	 */
	protected function finalizeDownload(Call\Track $track): void
	{
		$log = CallAISettings::isLoggingEnable();
		$logger = Logger::getInstance();

		$log && $logger->info("finalizeDownload: Starting. TrackId: {$track->getId()}");

		// Validate downloaded file
		$validateResult = DownloadHelper::validateFile($track);
		if (!$validateResult->isSuccess())
		{
			$log && $logger->error("finalizeDownload: Validation failed. TrackId: {$track->getId()}");
			$this->fireTrackErrorEvent($track, $validateResult->getError());
			return;
		}

		// Attach to file storage
		$attachResult = $track->attachTempFile();
		if (!$attachResult->isSuccess())
		{
			$log && $logger->error("finalizeDownload: attachTempFile failed. TrackId: {$track->getId()}");
			$this->fireTrackErrorEvent($track, $attachResult->getError());
			return;
		}

		// Attach to Disk (for audio records)
		if ($this->doNeedNeedAttachToDisk($track))
		{
			$diskResult = $track->attachToDisk();
			if (!$diskResult->isSuccess())
			{
				$log && $logger->error("finalizeDownload: attachToDisk failed. TrackId: {$track->getId()}");
				$this->fireTrackErrorEvent($track, $diskResult->getError());
				return;
			}
		}

		$log && $logger->info("finalizeDownload: Success. TrackId: {$track->getId()}, FileId: {$track->getFileId()}");

		$this->fireTrackDownloadedEvent($track);

		$processResult = $this->processTrack($track);
		if (!$processResult->isSuccess())
		{
			$call = Registry::getCallWithId($track->getCallId());
			if ($call)
			{
				(new FollowUpAnalytics($call))
					->sendTelemetry(
						source: null,
						status: 'error',
						event: 'track_processing_error',
						error: $processResult->getError()
					)
				;
			}
		}
	}

	/**
	 * Download track file - orchestrates the download process.
	 *
	 * @param Call\Track $track Track entity
	 * @param bool $retryOnFailure Schedule retry agent on failure
	 * @return Result
	 */
	public function downloadTrackFile(Call\Track $track, bool $retryOnFailure = true): Result
	{
		$result = new Result();

		if ($track->getFileId() > 0)
		{
			return $result->setData(['fileId' => $track->getFileId()]);
		}

		$log = CallAISettings::isLoggingEnable();
		$logger = Logger::getInstance();

		if (empty($track->getDownloadUrl()))
		{
			$log && $logger->error("downloadTrackFile: URL undefined. TrackId: {$track->getId()}");
			return $result->addError(new TrackError(TrackError::EMPTY_DOWNLOAD_URL, 'Download URL undefined'));
		}

		$log && $logger->info("downloadTrackFile: Starting. TrackId: {$track->getId()}, Url: {$track->getDownloadUrl()}");

		try
		{
			// Check Range support and choose downloader
			$rangeCheck = DownloadHelper::checkRangeSupport($track->getDownloadUrl());

			if ($rangeCheck['supports_range'] && $rangeCheck['file_size'] > 0)
			{
				$track->setFileSize($rangeCheck['file_size']);
				$downloader = new ChunkedDownloader($rangeCheck['file_size']);
			}
			else
			{
				$log && $logger->info("downloadTrackFile: No Range support, using FullDownloader. TrackId: {$track->getId()}");
				$downloader = new FullDownloader();
			}

			$downloader->setOnComplete($this->onDownloadCompleteCallback());

			$downloadResult = $downloader->download($track);
			if (!$downloadResult->isSuccess())
			{
				$result->addErrors($downloadResult->getErrors());
			}

			$downloadData = $downloadResult->getData();
			if (isset($downloadData['status']) && $downloadData['status'] === 'in_progress')
			{
				return $result->setData(['status' => 'in_progress']);
			}
		}
		catch (\Psr\Http\Client\ClientExceptionInterface $ex)
		{
			$log && $logger->error("downloadTrackFile: Exception: {$ex->getMessage()}. TrackId: {$track->getId()}");
			$result->addError(new TrackError(TrackError::DOWNLOAD_ERROR, $ex->getMessage()));
		}
		catch (SystemException $ex)
		{
			$log && $logger->error("downloadTrackFile: Exception: {$ex->getMessage()}. TrackId: {$track->getId()}");
			$result->addError(new TrackError(TrackError::DOWNLOAD_ERROR, $ex->getMessage()));
		}

		// Retry on failure
		if (!$result->isSuccess() && $retryOnFailure)
		{
			$log && $logger->info("downloadTrackFile: Scheduling retry. TrackId: {$track->getId()}");
			AbstractDownloader::scheduleRetry($track->getId());
			$this->fireTrackErrorEvent($track, $result->getError());
		}

		return $result;
	}

	/**
	 * Check and send audio record message after successful download
	 * Event handler for call:onCallTrackDownloaded
	 * @param Event $event
	 * @return void
	 */
	public static function onCallTrackDownloaded(Event $event): void
	{
		$track = $event->getParameter('track');
		if (!($track instanceof \Bitrix\Call\Track))
		{
			return;
		}

		if ($track->getType() !== \Bitrix\Call\Track::TYPE_RECORD)
		{
			return;
		}

		if ($log = CallAISettings::isLoggingEnable())
		{
			$logger = Logger::getInstance();
			$logger->info("Checking AI tasks after track download. TrackId: {$track->getId()}, CallId: {$track->getCallId()}");
		}

		$aiService = AI\CallAIService::getInstance();
		$aiResult = $aiService->checkCallAiTask($track->getCallId());
		if (!$aiResult->isSuccess() && !$aiService->hasExpectationAgent($track->getCallId()))
		{
			$log && $logger->info("AI task failed and no expectation agent found for call #{$track->getCallId()}");

			if (!Loader::includeModule('im'))
			{
				$log && $logger->error("Cannot load IM module");
				return;
			}

			$call = Registry::getCallWithId($track->getCallId());
			if ($call)
			{
				NotifyService::getInstance()->sendAudioRecordMessage($call);
				$log && $logger->info("Audio record message sent for call #{$track->getCallId()}");
			}
		}
	}

	/**
	 * @event call:onCallTrackReady
	 * @param Call\Track $track
	 * @return Event
	 */
	protected function fireTrackReadyEvent(Call\Track $track): Event
	{
		$event = new Event('call', 'onCallTrackReady', ['track' => $track]);
		$event->send();

		return $event;
	}

	/**
	 * @event call:onCallTrackError
	 * @param Call\Track $track
	 * @return Event
	 */
	protected function fireTrackErrorEvent(Call\Track $track, \Bitrix\Main\Error $error): Event
	{
		$event = new Event('call', 'onCallTrackError', ['track' => $track, 'error' => $error]);
		$event->send();

		return $event;
	}

	/**
	 * @event call:onCallTrackDownloaded
	 * @param Call\Track $track
	 * @return Event
	 */
	protected function fireTrackDownloadedEvent(Call\Track $track): Event
	{
		$event = new Event('call', 'onCallTrackDownloaded', ['track' => $track]);
		$event->send();

		return $event;
	}
}

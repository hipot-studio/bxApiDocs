<?php

namespace Bitrix\Call\Track;

use Bitrix\Call\Track;
use Bitrix\Call\Track\Downloader\DownloadAgent;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Call;
use Bitrix\Call\Call\Registry;
use Bitrix\Call\NotifyService;
use Bitrix\Call\Logger\Logger;
use Bitrix\Call\Integration\AI;
use Bitrix\Call\Integration\AI\CallAIError;
use Bitrix\Call\Integration\AI\CallAISettings;
use Bitrix\Call\Integration\AI\Task\AITask;
use Bitrix\Call\Analytics\FollowUpAnalytics;
use Bitrix\Call\Track\Downloader\AbstractDownloader;
use Bitrix\Call\Track\Downloader\DownloadHelper;
use Bitrix\Main\Application;
use Bitrix\Main\IO\File;


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

	/**
	 * Create default preview track from static file
	 *
	 * @param int $callId Call ID
	 * @return Result Contains created Track in data['track'] on success
	 */
	public function createDefaultPreview(int $callId): Result
	{
		$result = new Result();

		$log = CallAISettings::isLoggingEnable();
		$logger = Logger::getInstance();

		$log && $logger->info("TrackService::createDefaultPreview: Starting. CallId: {$callId}");

		$existingPreview = Track::getTrackForCall($callId, Track::TYPE_VIDEO_PREVIEW);
		if ($existingPreview)
		{
			$log && $logger->info("TrackService::createDefaultPreview: Preview already exists. CallId: {$callId}");
			return $result->addError(new TrackError(
				TrackError::PREVIEW_ALREADY_EXISTS,
				'Preview track already exists for this call'
			));
		}

		//TODO: required preview support for other regions
		$previewPath = Application::getDocumentRoot() . '/bitrix/images/call/cloud/preview_ru.png';
		if (!File::isFileExists($previewPath))
		{
			$log && $logger->error("TrackService::createDefaultPreview: Static file not found: {$previewPath}");
			return $result->addError(new TrackError(
				TrackError::STATIC_PREVIEW_NOT_FOUND,
				'Static preview file not found: ' . $previewPath
			));
		}

		$track = (new Call\Track)
			->setCallId($callId)
			->setType(Call\Track::TYPE_VIDEO_PREVIEW)
			->setDownloadUrl('')
			->setFileName("preview_default_{$callId}.png")
			->setFileMimeType('image/png')
			->setDownloaded(true)
		;

		$attachResult = $track->attachFileFromPath($previewPath, 'image/png');
		if (!$attachResult->isSuccess())
		{
			$log && $logger->error("TrackService::createDefaultPreview: Could not attach file. CallId: {$callId}");
			return $result->addErrors($attachResult->getErrors());
		}

		$saveResult = $track->save();
		if (!$saveResult->isSuccess())
		{
			$log && $logger->error("TrackService::createDefaultPreview: Could not save track. CallId: {$callId}");
			return $result->addErrors($saveResult->getErrors());
		}

		$fileId = $track->getFileId();
		$log && $logger->info("TrackService::createDefaultPreview: Success. CallId: {$callId}, FileId: {$fileId}");

		return $result->setData(['track' => $track]);
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
			return $this->processCloudTrack($track);
		}

		return $this->processAiTrack($track, $result);
	}

	/**
	 * Process cloud recording track based on MIME type
	 *
	 * Routes to appropriate handler: video/image -> processCloudVideoTrack,
	 * audio -> processAudioTrack, unknown -> error
	 *
	 * @param Call\Track $track Cloud track to process
	 * @return Result
	 */
	public function processCloudTrack(Call\Track $track): Result
	{
		if (
			str_starts_with($track->getFileMimeType(), 'video/')
			|| str_starts_with($track->getFileMimeType(), 'image/')
		)
		{
			return $this->processCloudVideoTrack($track);
		}

		if (str_starts_with($track->getFileMimeType(), 'audio/'))
		{
			return $this->processCloudAudioTrack($track);
		}

		// Unknown/unsupported MIME type
		$result = new Result();

		if ($log = CallAISettings::isLoggingEnable())
		{
			$logger = Logger::getInstance();
			$logger->error("Unsupported MIME type for cloud track. TrackId: {$track->getId()}, MimeType: {$track->getFileMimeType()}");
		}

		$call = Registry::getCallWithId($track->getCallId());
		if ($call)
		{
			(new FollowUpAnalytics($call))
				->sendTelemetry(
					source: null,
					status: 'error',
					errorCode: 'unsupported_mime_type',
					event: 'cloud_track_processing_error_' . $track->getId(),
				);
		}

		return $result->addError(new \Bitrix\Main\Error(
			"Unsupported MIME type: {$track->getFileMimeType()}",
			'UNSUPPORTED_MIME_TYPE'
		));
	}

	protected function processCloudVideoTrack(Call\Track $track): Result
	{
		$result = new Result();

		if ($log = CallAISettings::isLoggingEnable())
		{
			$logger = Logger::getInstance();
		}

		$log && $logger->info("Processing cloud video track. TrackId: {$track->getId()}, Type: {$track->getType()}");

		if (!Loader::includeModule('im'))
		{
			$log && $logger->error("IM module not loaded. TrackId: {$track->getId()}");
			return $result->addError(new \Bitrix\Main\Error('IM module not loaded', 'IM_MODULE_NOT_LOADED'));
		}

		$callId = $track->getCallId();

		$record = Track::getTrackForCall($callId, Track::TYPE_VIDEO_RECORD);
		$preview = Track::getTrackForCall($callId, Track::TYPE_VIDEO_PREVIEW);

		// Check if tracks are exists
		if (!$record)
		{
			$log && $logger->info("Waiting for record track. CallId: {$callId}");
			return $result;
		}

		if (!$preview)
		{
			$log && $logger->info("Waiting for preview track. CallId: {$callId}");
			return $result;
		}

		// If record is downloaded but preview is still downloading, continue waiting
		// CloudRecordExpectationAgent will handle the fallback to default preview
		if ($record->getDownloaded() && !$preview->getDownloaded())
		{
			$log && $logger->info("Record downloaded, preview still downloading. Waiting for CloudRecordExpectationAgent. CallId: {$callId}");
			return $result;
		}

		// If both are not downloaded, continue waiting
		if (!$record->getDownloaded() || !$preview->getDownloaded())
		{
			$log && $logger->info("Waiting for downloads. CallId: {$callId}, Record: "
				. ($record->getDownloaded() ? 'yes' : 'no')
				. ", Preview: " . ($preview->getDownloaded() ? 'yes' : 'no'));
			return $result;
		}

		// Both are downloaded - check file IDs
		if (!$record->getFileId() || !$preview->getFileId())
		{
			$log && $logger->error("Missing file IDs. RecordFileId: {$record->getFileId()}, PreviewFileId: {$preview->getFileId()}");
			return $result->addError(new \Bitrix\Main\Error(
				"Missing file IDs. RecordFileId: {$record->getFileId()}, PreviewFileId: {$preview->getFileId()}",
				'MISSING_FILE_IDS'
			));
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

			// Remove expectation agent if it was scheduled
			CloudRecordExpectationAgent::removeAgent($callId);

			TrackDeletionService::getInstance()->deleteCloudTracksFromMixer($callId);
		}
		else
		{
			$log && $logger->error("Call not found. CallId: {$callId}");
		}

		return $result;
	}

	/**
	 * Process audio track without preview
	 *
	 * @param Call\Track $track Audio track
	 * @param Result $result Result object
	 * @return Result
	 */
	protected function processCloudAudioTrack(Call\Track $track): Result
	{
		$result = new Result();

		if ($log = CallAISettings::isLoggingEnable())
		{
			$logger = Logger::getInstance();
		}

		$log && $logger->info("Processing audio track. TrackId: {$track->getId()}, Type: {$track->getType()}");

		if (!Loader::includeModule('im'))
		{
			$log && $logger->error("IM module not loaded. TrackId: {$track->getId()}");
			return $result->addError(new \Bitrix\Main\Error('IM module not loaded', 'IM_MODULE_NOT_LOADED'));
		}

		$callId = $track->getCallId();
		$call = Registry::getCallWithId($callId);

		if (!$call)
		{
			$log && $logger->error("Call not found. CallId: {$callId}");
			return $result->addError(new \Bitrix\Main\Error("Call not found: {$callId}", 'CALL_NOT_FOUND'));
		}

		$record = Track::getTrackForCall($callId, Track::TYPE_VIDEO_RECORD);

		if (!$record)
		{
			$log && $logger->info("Waiting for record track. CallId: {$callId}");
			return $result;
		}

		if (!$record->getDownloaded())
		{
			$log && $logger->info(
				"Waiting for downloads. CallId: {$callId}, Record: "
				. ($record->getDownloaded() ? 'yes' : 'no')
			);
			return $result;
		}

		if (!$record->getFileId())
		{
			$log && $logger->error("Missing file ID. RecordFileId: {$record->getFileId()}");
			return $result->addError(new \Bitrix\Main\Error(
				"Missing file ID. RecordFileId: {$record->getFileId()}",
				'MISSING_FILE_IDS'
			));
		}

		// Send audio record message to chat
		$log && $logger->info("Sending audio record message. CallId: {$callId}, TrackId: {$track->getId()}");

		$log && $logger->info("Sending recording ready message. CallId: {$callId}, RecordId: {$record->getId()}");
		NotifyService::getInstance()->sendRecordingReadyMessage($call, $record);

		// Remove expectation agent if it was scheduled
		CloudRecordExpectationAgent::removeAgent($callId);

		TrackDeletionService::getInstance()->deleteCloudTracksFromMixer($callId);

		return $result;
	}

	protected function processAiTrack(Call\Track $track): Result
	{
		$result = new Result();

		if ($log = CallAISettings::isLoggingEnable())
		{
			$logger = Logger::getInstance();
		}

		TrackDeletionService::getInstance()->tryDeleteAiTracksFromMixer($track->getCallId());

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
	 * Finalize download: validate, attach to storage, fire events, process track.
	 *
	 * @param Call\Track $track
	 */
	public function finalizeDownload(Call\Track $track): void
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

		$this->checkAiTasksExecution($track);

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
						event: 'track_processing_error_' . $track->getId(),
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

		$log && $logger->info("downloadTrackFile: Scheduling download agent. TrackId: {$track->getId()}");

		try
		{
			DownloadAgent::schedule($track->getId());
			return $result->setData(['status' => 'scheduled']);
		}
		catch (\Psr\Http\Client\ClientExceptionInterface | SystemException $ex)
		{
			$log && $logger->error("downloadTrackFile: Exception: {$ex->getMessage()}. TrackId: {$track->getId()}");
			$result->addError(new TrackError(TrackError::DOWNLOAD_ERROR, $ex->getMessage()));
		}

		return $result;
	}

	/**
	 * Check AI tasks execution and send audio record message if needed
	 *
	 * If AI task failed and no expectation agent exists, sends audio record
	 * notification to chat as fallback.
	 *
	 * @param Track $track Downloaded track (must be TYPE_RECORD)
	 */
	public static function checkAiTasksExecution(Track $track): void
	{
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
	 * Event handler for call:onCallTrackDownloadCompleted
	 * Called immediately after download completes, before finalization
	 *
	 * @event call:onCallTrackDownloadCompleted
	 * @param Event $event
	 */
	public static function onCallTrackDownloadCompleted(Event $event): EventResult
	{
		$track = $event->getParameter('track');
		if (!($track instanceof \Bitrix\Call\Track))
		{
			return new EventResult(EventResult::ERROR);
		}

		$log = CallAISettings::isLoggingEnable();
		if ($log)
		{
			$logger = Logger::getInstance();
			$logger->info("onCallTrackDownloadCompleted: Starting finalization. TrackId: {$track->getId()}");
		}

		$service = self::getInstance();
		$service->finalizeDownload($track);

		return new EventResult(EventResult::SUCCESS);
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
}

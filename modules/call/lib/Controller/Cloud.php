<?php

namespace Bitrix\Call\Controller;

use Bitrix\Call\Analytics\FollowUpAnalytics;
use Bitrix\Call\Integration\AI\CallAISettings;
use Bitrix\Call\Logger\Logger;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Loader;
use Bitrix\Main\Error;
use Bitrix\Main\Service\MicroService\BaseReceiver;
use Bitrix\Call\Track;
use Bitrix\Call\Model\CallTrackTable;
use Bitrix\Call\Track\TrackService;
use Bitrix\Call\Track\CloudRecordExpectationAgent;
use Bitrix\Call\DTO\CloudRecordingRequest;
use Bitrix\Call\DTO\CloudRecordingErrorRequest;
use Bitrix\Call\DTO\FileInfo;
use Bitrix\Call\NotifyService;
use Bitrix\Call\CallChatMessage;
use Bitrix\Call\Call\Registry;
use Bitrix\Im\V2\Message\Send\SendingConfig;
use Bitrix\Im\V2\Service\Context;
use Bitrix\Im\V2\Chat;


/**
 * @internal
 */
class Cloud extends BaseReceiver
{
	public function getAutoWiredParameters(): array
	{
		return array_merge([
			new ExactParameter(
				CloudRecordingRequest::class,
				'recordingRequest',
				function ($className, $params = [])
				{
					return new $className($this->getSourceParametersList()[0]);
				}
			),
			new ExactParameter(
				CloudRecordingErrorRequest::class,
				'recordingError',
				function ($className, $params = [])
				{
					return new $className($this->getSourceParametersList()[0]);
				}
			),
		], parent::getAutoWiredParameters());
	}

	/**
	 * @restMethod call.Cloud.recordingPrepare
	 *
	 * @param CloudRecordingRequest $recordingRequest
	 * @return array|null
	 */
	public function recordingPrepareAction(CloudRecordingRequest $recordingRequest): ?array
	{
		if (!Loader::includeModule('im'))
		{
			$this->addError(new Error('IM module not loaded', 'im_module_not_loaded'));
			return null;
		}

		if (!$recordingRequest->roomId)
		{
			$this->addError(new Error('One of Room ID is required', 'room_id_required'));
			return null;
		}

		$call = Registry::getCallWithUuid($recordingRequest->roomId);
		if (!$call)
		{
			$this->addError(new Error('Call not found', 'call_not_found'));
			return null;
		}

		$chat = Chat::getInstance($call->getChatId());
		if (!$chat || $chat instanceof \Bitrix\Im\V2\Chat\NullChat)
		{
			$this->addError(new Error('Chat not found', 'chat_not_found'));
			return null;
		}

		if (NotifyService::getInstance()->findMessage($chat->getId(), $call->getId(), NotifyService::MESSAGE_TYPE_CLOUD_RECORD_PREPARE) !== null)
		{
			return ['result' => true];
		}

		$message = CallChatMessage::makeCloudRecordPrepareMessage($call, $chat);

		$sendingConfig = (new SendingConfig())
			->enableSkipCounterIncrements()
			->enableSkipUrlIndex()
		;
		$context = (new Context())->setUser($call->getInitiatorId());

		NotifyService::getInstance()->sendMessageDeferred($chat, $message, $sendingConfig, $context);

		return ['result' => true];
	}

	/**
	 * @restMethod call.Cloud.recordingReady
	 *
	 * @param CloudRecordingRequest $recordingRequest
	 * @return array|null
	 */
	public function recordingReadyAction(CloudRecordingRequest $recordingRequest): ?array
	{
		Loader::includeModule('im');

		$log = CallAISettings::isLoggingEnable();
		$logger = Logger::getInstance();

		$log && $logger->info('Cloud::recordingReadyAction: Starting. {' . var_export($recordingRequest, true) . '}');

		if (!$recordingRequest->roomId || !$recordingRequest->recording)
		{
			$log && $logger->error("Cloud::recordingReadyAction: bad input");
			$this->addError(new Error('Bad input', 'bad_input'));
			return null;
		}

		$call = Registry::getCallWithUuid($recordingRequest->roomId);
		if (!$call)
		{
			$log && $logger->error("Cloud::recordingReadyAction: call {$recordingRequest->roomId} not found");
			$this->addError(new Error('Call not found', 'call_not_found'));
			return null;
		}

		(new FollowUpAnalytics($call))
			->sendTelemetry(
				source: null,
				status: 'success',
				event: 'cloud_record_ready_action'
			);

		$recordingData = $recordingRequest->recording;
		$recordingData['type'] = Track::TYPE_VIDEO_RECORD;
		$recordTrack = $this->makeTrack($call, new FileInfo($recordingData));
		if (!$recordTrack)
		{
			$log && $logger->error("Cloud::recordingReadyAction: failed to create record track");
			(new FollowUpAnalytics($call))
				->sendTelemetry(
					source: null,
					status: 'success',
					event: 'cloud_record_track_not_created'
				);
			return null;
		}

		$log && $logger->info("Cloud::recordingReadyAction: record track created: {$recordTrack->getId()}");
		(new FollowUpAnalytics($call))
			->sendTelemetry(
				source: null,
				status: 'success',
				event: 'cloud_record_track_created_' . $recordTrack->getId()
			);

		$previewTrack = null;
		if ($recordingRequest->preview)
		{
			$log && $logger->info("Cloud::recordingReadyAction: make preview track from request");

			$previewData = $recordingRequest->preview;
			$previewData['type'] = Track::TYPE_VIDEO_PREVIEW;
			$previewTrack = $this->makeTrack($call, new FileInfo($previewData));
		}

		if (!$recordingRequest->preview && str_starts_with($recordTrack->getFileMimeType(), 'video/'))
		{
			$log && $logger->info("Cloud::recordingReadyAction: make default preview track");
			$result = TrackService::getInstance()->createDefaultPreview($call->getId());
			if (!$result->isSuccess())
			{
				$log && $logger->error(
					"Cloud::recordingReadyAction: failed to create preview track -> "
					. implode("\n", $result->getErrors())
				);
				$this->addErrors($result->getErrors());
			}
			else
			{
				$previewTrack = $result->getData()['track'];
			}
		}

		if ($previewTrack)
		{
			$log && $logger->info("Cloud::recordingReadyAction: preview track created: {$previewTrack->getId()}");
			(new FollowUpAnalytics($call))
				->sendTelemetry(
					source: null,
					status: 'success',
					event: 'cloud_record_preview_track_created_' . $previewTrack->getId()
				);
		}

		$result = true;
		$downloadStarted = false;

		if ($previewTrack && !$previewTrack->getDownloaded())
		{
			$log && $logger->info("Cloud::recordingReadyAction: start to download preview track");
			(new FollowUpAnalytics($call))
				->sendTelemetry(
					source: null,
					status: 'success',
					event: 'cloud_record_start_download_preview_track_' . $previewTrack->getId()
				);

			$result &= $this->downloadTrack($previewTrack);
			$downloadStarted |= $result;
		}

		if (!$recordTrack->getDownloaded())
		{
			$log && $logger->info("Cloud::recordingReadyAction: start to download record track");
			(new FollowUpAnalytics($call))
				->sendTelemetry(
					source: null,
					status: 'success',
					event: 'cloud_record_start_download_track_' . $recordTrack->getId()
				);

			$result &= $this->downloadTrack($recordTrack);
			$downloadStarted |= $result;
		}

		if ($downloadStarted)
		{
			CloudRecordExpectationAgent::scheduleAgent($call->getId());
		}

		$log && $logger->info("Cloud::recordingReadyAction: finish. Result: " . ($result ? 'true' : 'false'));
		return ['result' => $result];
	}

	/**
	 * Create track record in database from file info
	 *
	 * @param \Bitrix\Call\Call $call Call instance
	 * @param FileInfo $fileInfo File information (url, name, mime, size, type)
	 * @return Track|null Track instance on success, null on failure
	 */
	private function makeTrack(\Bitrix\Call\Call $call, FileInfo $fileInfo): ?Track
	{
		$log = CallAISettings::isLoggingEnable();
		$logger = Logger::getInstance();

		$log && $logger->info("Cloud::makeTrack: starting. Create {$fileInfo->type} track for call {$call->getId()}");

		$trackList = CallTrackTable::query()
			->setSelect(['ID'])
			->where('CALL_ID', $call->getId())
			->where('TYPE', $fileInfo->type)
			->setLimit(1)
			->exec();
		if ($trackList->getSelectedRowsCount() > 0) {
			$existingTrack = $trackList->fetch();
			$log && $logger->info("Cloud::makeTrack: Track already exists: {$existingTrack['ID']}");
			return CallTrackTable::getById($existingTrack['ID'])->fetchObject();
		}

		// Create track record
		$track = (new Track)
			->setCallId($call->getId())
			->setExternalTrackId($fileInfo->id)
			->setDownloadUrl($fileInfo->url)
			->setType($fileInfo->type)
		;

		$mime = \Bitrix\Main\Web\MimeType::normalize($fileInfo->mime);
		if ($mime)
		{
			$track->setFileMimeType($mime);
		}

		if ($fileInfo->name)
		{
			$track->setFileName($fileInfo->name);
		}
		$track->generateFilename();

		if ($fileInfo->duration)
		{
			$track->setDuration($fileInfo->duration);
		}

		if ($fileInfo->size)
		{
			$track->setFileSize($fileInfo->size);
		}

		$saveResult = $track->save();
		if (!$saveResult->isSuccess())
		{
			$log && $logger->error(
				"Cloud::makeTrack: failed to save {$fileInfo->type} track for call {$call->getId()} -> "
				. implode("\n", $saveResult->getErrors())
			);
			$this->addErrors($saveResult->getErrors());
			return null;
		}

		$log && $logger->info("Cloud::makeTrack: finish. {$fileInfo->type} track for call {$call->getId()} created: {$track->getId()}");

		return $track;
	}

	/**
	 * Schedule track file download via agent
	 *
	 * @param Track $track Track to download
	 * @return bool True on success, false on failure
	 */
	private function downloadTrack(Track $track): bool
	{
		$trackService = TrackService::getInstance();
		if ($trackService->doNeedDownloadTrack($track))
		{
			$downloadResult = $trackService->downloadTrackFile($track, true);
			if (!$downloadResult->isSuccess())
			{
				$this->addErrors($downloadResult->getErrors());
				return false;
			}
		}

		return true;
	}

	/**
	 * @restMethod call.Cloud.recordingError
	 *
	 * @param CloudRecordingErrorRequest $recordingError
	 * @return array|null
	 */
	public function recordingErrorAction(CloudRecordingErrorRequest $recordingError): ?array
	{
		if (!Loader::includeModule('im'))
		{
			$this->addError(new Error('IM module not loaded', 'im_module_not_loaded'));
			return null;
		}

		if (!$recordingError->roomId)
		{
			$this->addError(new Error('Room ID is required', 'room_id_required'));
			return null;
		}

		$call = Registry::getCallWithUuid($recordingError->roomId);
		if (!$call)
		{
			$this->addError(new Error('Call not found', 'call_not_found'));
			return null;
		}

		$chat = Chat::getInstance($call->getChatId());
		if ($chat && !($chat instanceof \Bitrix\Im\V2\Chat\NullChat))
		{
			$errorText = $recordingError->errorMessage ?: $recordingError->errorCode;
			$message = CallChatMessage::makeCloudRecordErrorMessage($call, $chat, $errorText);

			$sendingConfig = (new SendingConfig())
				->enableSkipCounterIncrements()
				->enableSkipUrlIndex()
			;
			$context = (new Context())->setUser($call->getInitiatorId());

			NotifyService::getInstance()->sendMessageDeferred($chat, $message, $sendingConfig, $context);
		}

		return ['result' => true];
	}
}

<?php

namespace Bitrix\Call\Controller;

use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Loader;
use Bitrix\Main\Error;
use Bitrix\Main\Service\MicroService\BaseReceiver;
use Bitrix\Call\Track;
use Bitrix\Call\Model\CallTrackTable;
use Bitrix\Call\Track\TrackService;
use Bitrix\Call\DTO\CloudRecordingRequest;
use Bitrix\Call\DTO\CloudRecordingErrorRequest;
use Bitrix\Call\DTO\FileInfo;
use Bitrix\Call\NotifyService;
use Bitrix\Call\CallChatMessage;
use Bitrix\Im\Call\Registry;
use Bitrix\Im\V2\Message\Send\SendingConfig;
use Bitrix\Im\V2\Service\Context;
use Bitrix\Im\V2\Chat;


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

		if (!$recordingRequest->roomId)
		{
			$this->addError(new Error('Room Id is required', 'room_id_required'));
			return null;
		}

		$call = Registry::getCallWithUuid($recordingRequest->roomId);
		if (!$call)
		{
			$this->addError(new Error('Call not found', 'call_not_found'));
			return null;
		}

		if ($recordingRequest->recording)
		{
			$recordingData = $recordingRequest->recording;
			if (is_array($recordingData) && empty($recordingData['type']))
			{
				$recordingData['type'] = Track::TYPE_VIDEO_RECORD;
			}
			$recording = new FileInfo($recordingData);
			$record = $this->downloadTrack($call, $recording);
			if (!$record)
			{
				return null;
			}
		}

		if ($recordingRequest->preview)
		{
			$previewData = $recordingRequest->preview;
			if (is_array($previewData) && empty($previewData['type']))
			{
				$previewData['type'] = Track::TYPE_VIDEO_PREVIEW;
			}
			$preview = new FileInfo($previewData);
			$preview_track = $this->downloadTrack($call, $preview);
			if (!$preview_track)
			{
				return null;
			}
		}

		return ['result' => true];
	}

	/**
	 * Download and create track record from file info
	 *
	 * Creates a new track record in the database, downloads the file from the provided URL,
	 * and attaches it to Bitrix Disk if needed. Handles both video recordings and preview files.
	 *
	 * @param \Bitrix\Im\Call\Call $call The call instance to associate the track with
	 * @param FileInfo $fileInfo File information containing URL, name, mime type, size, etc.
	 * @return Track|null Returns Track instance on success, null on failure
	 * 
	 * @see FileInfo For file information structure
	 * @see Track For track entity details
	 * @see TrackService::downloadTrackFile() For actual file download logic
	 * 
	 * @example
	 * $fileInfo = new FileInfo([
	 *     'url' => 'https://example.com/video.mp4',
	 *     'name' => 'recording.mp4',
	 *     'mime' => 'video/mp4',
	 *     'size' => 1024000,
	 *     'type' => Track::TYPE_VIDEO_RECORD
	 * ]);
	 * $track = $this->downloadTrack($call, $fileInfo);
	 */
	private function downloadTrack(\Bitrix\Im\Call\Call $call, FileInfo $fileInfo): ?Track
	{
		$trackList = CallTrackTable::query()
			->setSelect(['ID'])
			->where('CALL_ID', $call->getId())
			->where('DOWNLOAD_URL', $fileInfo->url)
			->setLimit(1)
			->exec()
		;
		if ($trackList->getSelectedRowsCount() > 0)
		{
			$this->addError(new Error('Track already exists', 'track_duplicate_error'));
			return null;
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
			$this->addErrors($saveResult->getErrors());
			return null;
		}

		$trackService = TrackService::getInstance();
		if ($trackService->doNeedDownloadTrack($track))
		{
			$downloadResult = $trackService->downloadTrackFile($track, true);
			if (!$downloadResult->isSuccess())
			{
				$this->addErrors($downloadResult->getErrors());
				return null;
			}
		}

		return $track;
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

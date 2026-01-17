<?php

namespace Bitrix\Call\Controller;

use Bitrix\Call\DTO\CloudRecordingRequest;
use Bitrix\Call\DTO\CloudRecordingErrorRequest;
use Bitrix\Call\DTO\FileInfo;
use Bitrix\Call\Integration\AI\ChatMessage;
use Bitrix\Im\Call\Registry;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Error;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Message;
use Bitrix\Call\Track;
use Bitrix\Call\Model\CallTrackTable;
use Bitrix\Call\Track\TrackService;
use Bitrix\Call\Controller\Filter\UniqueRequestFilter;
use Bitrix\Main\UI\Viewer\PreviewManager;
use Bitrix\Main\Service\MicroService\BaseReceiver;

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

		$chatId = $call->getChatId();

		// Get chat instance
		$chat = Chat::getInstance($chatId);
		if (!$chat || $chat instanceof \Bitrix\Im\V2\Chat\NullChat)
		{
			$this->addError(new Error('Chat not found', 'chat_not_found'));
			return null;
		}

		// Create and send message to chat
		$message = new Message();
		$message->setMessage(Loc::getMessage('CALL_CLOUD_RECORDING_PREPARE_MESSAGE'));
		$message->markAsSystem(true);

		$sendResult = $chat->sendMessage($message);

		if (!$sendResult->isSuccess())
		{
			$this->addErrors($sendResult->getErrors());
			return null;
		}

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

		$record = null;
		if ($recordingRequest->recording)
		{
			$recordingData = $recordingRequest->recording;
			if (is_array($recordingData) && empty($recordingData['type']))
			{
				$recordingData['type'] = Track::TYPE_VIDEO_RECORD;
			}
			$recording = new FileInfo($recordingData);
			$record = $this->downloadTrack($call, $recording);
		}
		if (!$record)
		{
			return null;
		}

		if ($recordingRequest->preview)
		{
			$previewData = $recordingRequest->preview;
			if (is_array($previewData) && empty($previewData['type']))
			{
				$previewData['type'] = Track::TYPE_VIDEO_PREVIEW;
			}
			$preview = new FileInfo($previewData);
			$preview = $this->downloadTrack($call, $preview);
			if (!$preview)
			{
				return null;
			}

			(new PreviewManager())->setPreviewImageId($record->getFileId(), $preview->getFileId());
		}

		$this->sendRecordingReadyMessage($call, $record);

		return ['result' => true];
	}

	/**
	 * Send message to chat about recording ready
	 *
	 * @param \Bitrix\Im\Call\Call $call
	 * @param Track $track
	 * @return void
	 */
	private function sendRecordingReadyMessage(\Bitrix\Im\Call\Call $call, Track $track): void
	{
		Loader::includeModule('im');

		$chat = Chat::getInstance($call->getChatId());
		if (!$chat || $chat instanceof \Bitrix\Im\V2\Chat\NullChat)
		{
			return;
		}

		$userId = $call->getActionUserId() ?: $call->getInitiatorId();
		if ($track->getFileId() && !$track->getDiskFileId())
		{
			$diskFileIds = \CIMDisk::UploadFileFromMain(
				$call->getChatId(),
				[$track->getFileId()],
				$userId
			);

			if (!$diskFileIds || empty($diskFileIds[0]))
			{
				return;
			}

			$diskFileId = $diskFileIds[0];
			$track->setDiskFileId($diskFileId);
			$track->save();
		}

		if ($track->getDiskFileId())
		{
			\CIMDisk::UploadFileFromDisk(
				$call->getChatId(),
				['upload' . $track->getDiskFileId()],
				'',
				['USER_ID' => $userId]
			);
		}

		// Try to get direct download URL from Disk
		$downloadUrl = null;
		if ($track->getDiskFileId() && Loader::includeModule('disk'))
		{
			$diskFile = \Bitrix\Disk\File::getById($track->getDiskFileId());
			if ($diskFile)
			{
				$urlManager = \Bitrix\Disk\Driver::getInstance()->getUrlManager();
				$downloadUrl = $urlManager->getUrlForDownloadFile($diskFile, true);
			}
		}

		// Fallback to controller URL if Disk URL is not available
		if (!$downloadUrl)
		{
			$downloadUrl = $track->getUrl(true, true);
		}

		$messageUrl = ChatMessage::makeCallStartMessageLink($call->getId(), $chat->getId());

		$message = new Message();
		$message->setMessage(Loc::getMessage('CALL_CLOUD_RECORDING_READY_MESSAGE', [
			'#DOWNLOAD_URL#' => $downloadUrl,
			'#CALL_ID#' => $call->getId(),
			'#CALL_START#' => $messageUrl,
		]));
		$message->markAsSystem(true);
		$chat->sendMessage($message);
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
			$messageUrl = ChatMessage::makeCallStartMessageLink($call->getId(), $chat->getId());

			$message = new Message();
			$message->setMessage(Loc::getMessage('CALL_CLOUD_RECORDING_ERROR_MESSAGE', [
				'#ERROR#' => $errorText,
				'#CALL_ID#' => $call->getId(),
				'#CALL_START#' => $messageUrl,
			]));
			$message->markAsSystem(true);
			$chat->sendMessage($message);
		}

		return ['result' => true];
	}
}

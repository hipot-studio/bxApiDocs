<?php

namespace Bitrix\Call\Controller;

use Bitrix\Call\Analytics\CloudRecordAnalytics;
use Bitrix\Call\Analytics\TrackAnalytics;
use Bitrix\Call\Integration\AI\CallAISettings;
use Bitrix\Call\Logger\Logger;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Application;
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
use Bitrix\Call\Controller\Filter\UniqueRequestFilter;
use Bitrix\Call\NotifyService;
use Bitrix\Call\CallChatMessage;
use Bitrix\Call\Call\Registry;
use Bitrix\Im\V2\Message\Send\SendingConfig;
use Bitrix\Im\V2\Service\Context;
use Bitrix\Im\V2\Chat;


/**
 * @internal
 */
class Cloud extends BaseReceiver implements IdempotentReplayable
{
	use IdempotentReplayableTrait;

	private const LOCK_TTL = 10; // in seconds

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
	 * @return array[]
	 */
	public function configureActions(): array
	{
		return [
			'recordingPrepare' => [
				'+prefilters' => [
					new UniqueRequestFilter(),
				],
			],
			'recordingReady' => [
				'+prefilters' => [
					new UniqueRequestFilter(),
				],
			],
			'recordingError' => [
				'+prefilters' => [
					new UniqueRequestFilter(),
				],
			],
		];
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

		CloudRecordExpectationAgent::scheduleAgent($call->getId());

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

		// Claim-before-send on the (callId, CLOUD_RECORD_PREPARE) key: the DB check above is
		// not atomic under concurrent recordingPrepare callbacks. A loser acks the duplicate
		// as an idempotent success, exactly like the DB-hit branch above.
		if (!NotifyService::getInstance()->claim($call->getId(), NotifyService::MESSAGE_TYPE_CLOUD_RECORD_PREPARE))
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

		(new CloudRecordAnalytics($call))
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
			(new CloudRecordAnalytics($call))
				->sendTelemetry(
					source: null,
					status: 'error',
					errorCode: 'track_not_created',
					event: 'cloud_record_track_not_created'
				);
			$this->addError(new Error('Track not created', 'track_not_created'));

			return null;
		}

		$log && $logger->info("Cloud::recordingReadyAction: record track created: {$recordTrack->getId()}");
		(new CloudRecordAnalytics($call))
			->sendTelemetry(
				source: $recordTrack,
				status: 'success',
				event: 'cloud_record_track_created'
			);

		$previewTrack = null;
		if ($recordingRequest->preview)
		{
			$log && $logger->info("Cloud::recordingReadyAction: make preview track from request");

			$previewData = $recordingRequest->preview;
			$previewData['type'] = Track::TYPE_VIDEO_PREVIEW;
			$previewTrack = $this->makeTrack($call, new FileInfo($previewData));
		}

		if ($previewTrack)
		{
			$log && $logger->info("Cloud::recordingReadyAction: preview track created: {$previewTrack->getId()}");
			(new CloudRecordAnalytics($call))
				->sendTelemetry(
					source: $previewTrack,
					status: 'success',
					event: 'cloud_record_track_created'
				);
		}

		$result = true;
		$downloadStarted = false;

		if ($previewTrack && !$previewTrack->getDownloaded())
		{
			$log && $logger->info("Cloud::recordingReadyAction: start to download preview track");
			(new CloudRecordAnalytics($call))
				->sendTelemetry(
					source: $previewTrack,
					status: 'success',
					event: 'cloud_record_start_download_track'
				);

			$result &= $this->downloadTrack($previewTrack);
			$downloadStarted |= $result;
		}

		// A stored preview has no branch of its own: the pair always carries its record, and
		// processing the record resolves the stored preview, binds it to the record and
		// delivers the record. A second entry for the preview would deliver the same record
		// again, inside the window the service waits for the answer of the portal.
		if (!$recordTrack->getDownloaded())
		{
			$log && $logger->info("Cloud::recordingReadyAction: start to download record track");
			(new CloudRecordAnalytics($call))
				->sendTelemetry(
					source: $recordTrack,
					status: 'success',
					event: 'cloud_record_start_download_track'
				);

			$result &= $this->downloadTrack($recordTrack);
			$downloadStarted |= $result;
		}
		elseif ($recordingRequest->manualRetry)
		{
			// The file is already on the portal and the download would add nothing: what the
			// operator is missing is the delivery of it - the chat message and the preview bound
			// to the record. Without the resend mark a stored file is still skipped, so a media
			// retransmit cannot republish anything.
			$log && $logger->info("Cloud::recordingReadyAction: process stored record track on an operator-driven resend");

			// The preview of this pair is named whatever state it is in. A call may hold several
			// pairs, and leaving it unnamed makes the delivery resolve the newest preview of the
			// call instead - a foreign image bound to this record. Named, it decides the pair on
			// its own: ready, it is bound; still downloading, the delivery waits for its
			// completion; stuck without a download, it is replaced by a default
			// {@see TrackService::processCloudVideoTrack()}.
			$this->processDownloadedTrack($call, $recordTrack, $previewTrack);
		}

		if ($downloadStarted)
		{
			CloudRecordExpectationAgent::scheduleAgent($call->getId());
		}

		$log && $logger->info("Cloud::recordingReadyAction: finish. Result: " . ($result ? 'true' : 'false'));
		return ['result' => $result];
	}

	/**
	 * Find track by specified parameters
	 *
	 * Generic method to search for a track record using dynamic field conditions.
	 * Builds a query with WHERE clauses based on the provided parameters array.
	 *
	 * @param array $params Associative array of field => value pairs for filtering
	 *                      Example: ['CALL_ID' => 123, 'EXTERNAL_TRACK_ID' => 456]
	 *                      Example: ['CALL_ID' => 123, 'TYPE' => Track::TYPE_VIDEO_RECORD]
	 * @return Track|null Track object if found, null otherwise
	 */
	private function getTrack(array $params): ?Track
	{
		$query = CallTrackTable::query()
			->setSelect(['*'])
			->setLimit(1);

		foreach ($params as $field => $value)
		{
			$query->where($field, $value);
		}

		$trackList = $query->exec();

		return $trackList->fetchObject() ?: null;
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

		// Serialize find-or-create per file, the same way trackReadyAction() does. The replay keys
		// of a regular delivery and of an operator resend differ on purpose, so UniqueRequestFilter
		// lets a media retransmit and a resend of the same recording through together, and the
		// table carries no unique constraint on (CALL_ID, EXTERNAL_TRACK_ID) to fall back on:
		// without the lock both requests miss the stored row and insert one each, which doubles
		// the download and the delivery as well. Only the row is protected here - the download is
		// started by the caller, outside the lock.
		$lockName = $this->trackLockName($call, $fileInfo);
		if (!Application::getConnection()->lock($lockName, self::LOCK_TTL))
		{
			$log && $logger->error("Cloud::makeTrack: could not lock {$fileInfo->type} track of call {$call->getId()}");
			$this->addError(new Error('Could not get exclusive lock', 'could_not_lock'));

			return null;
		}

		try
		{
			return $this->findOrCreateTrack($call, $fileInfo);
		}
		finally
		{
			Application::getConnection()->unlock($lockName);
		}
	}

	/**
	 * The name of the lock follows the search of findOrCreateTrack(): a file with an external
	 * identifier is one row of the call, a file without one is one row per type.
	 */
	private function trackLockName(\Bitrix\Call\Call $call, FileInfo $fileInfo): string
	{
		$key = $fileInfo->id > 0 ? "ext_{$fileInfo->id}" : "type_{$fileInfo->type}";

		return "call_cloud_track_{$call->getId()}_{$key}";
	}

	/**
	 * @return Track|null Track instance on success, null on failure
	 */
	private function findOrCreateTrack(\Bitrix\Call\Call $call, FileInfo $fileInfo): ?Track
	{
		$log = CallAISettings::isLoggingEnable();
		$logger = Logger::getInstance();

		$searchParams = [
			'CALL_ID' => $call->getId(),
		];
		if ($fileInfo->id > 0)
		{
			$searchParams['EXTERNAL_TRACK_ID'] = $fileInfo->id;
		}
		else
		{
			$searchParams['TYPE'] = $fileInfo->type;
		}

		$existingTrack = $this->getTrack($searchParams);
		if ($existingTrack)
		{
			$log && $logger->info("Cloud::makeTrack: Track already exists: {$existingTrack->getId()}");
			return $existingTrack;
		}

		// Create new track
		$log && $logger->info("Cloud::makeTrack: Creating new track");

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
				. implode("\n", $saveResult->getErrorMessages())
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
		if (!$trackService->isDownloaded($track) && $trackService->hasDownloadSource($track))
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
	 * Processing of a file the portal already holds {@see TrackService::processDownloaded()}.
	 *
	 * Only an operator-driven resend gets here, and only for the record of the pair: processing
	 * it delivers the record with the preview bound to it, so the pair is processed once per
	 * request. The chat message about a ready recording stays single: it is guarded by the claim
	 * NotifyService holds per call, message type and track.
	 *
	 * The preview of this very pair is passed along: without it the record binds to the newest
	 * preview of the call, and a call with several pairs would deliver a resent record with the
	 * preview of another one.
	 *
	 * A failure of the delivery is the answer of the callback and an event of its own: the operator
	 * is told the file was not delivered instead of reading a success next to a file that never
	 * reached the chat. The failure is reported here and not inside the finalization, which stays
	 * silent about it for the sake of the background agent {@see TrackService::finalizeDownload()},
	 * so one failure makes one event.
	 */
	protected function processDownloadedTrack(\Bitrix\Call\Call $call, Track $track, ?Track $knownPreview = null): void
	{
		$processResult = TrackService::getInstance()->processDownloaded(
			$track,
			knownPreview: $knownPreview
		);
		if ($processResult->isSuccess())
		{
			return;
		}

		$this->addErrors($processResult->getErrors());
		(new TrackAnalytics($call))->sendTelemetry(
			source: $track,
			status: 'error',
			event: 'track_processing_error',
			error: $processResult->getError()
		);
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
			$callId = $call->getId();
			$showRetryButton =
				CloudRecordExpectationAgent::hasRetryableTracks($callId)
				&& !CloudRecordExpectationAgent::hasActiveDownloadAgentsForCall($callId)
			;

			if ($showRetryButton)
			{
				$message = CallChatMessage::makeCloudRecordRetryableErrorMessage($call, $chat);
			}
			else
			{
				$errorText = $recordingError->errorMessage ?: $recordingError->errorCode;
				$message = CallChatMessage::makeCloudRecordErrorMessage($call, $chat, $errorText);
			}

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

<?php

namespace Bitrix\Call\Controller;

use Bitrix\Main\Loader;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Service\MicroService\BaseReceiver;
use Bitrix\Im\Call\Call;
use Bitrix\Im\Call\CallUser;
use Bitrix\Im\V2\Call\CallFactory;
use Bitrix\Call\Error;
use Bitrix\Call\Logger\Logger;
use Bitrix\Call\Track\TrackError;
use Bitrix\Call\Track\TrackService;
use Bitrix\Call\DTO\TrackFileRequest;
use Bitrix\Call\DTO\TrackErrorRequest;
use Bitrix\Call\DTO\ControllerRequest;
use Bitrix\Call\Model\CallTrackTable;
use Bitrix\Call\Integration\AI\CallAIError;
use Bitrix\Call\Integration\AI\CallAISettings;
use Bitrix\Call\Integration\AI\CallAIService;
use Bitrix\Call\Analytics\FollowUpAnalytics;


class CallController extends BaseReceiver
{
	public function getAutoWiredParameters(): array
	{
		return array_merge([
			new ExactParameter(
				TrackFileRequest::class,
				'trackFile',
				function ($className, $params = [])
				{
					return new $className($this->getSourceParametersList()[0]);
				}
			),
			new ExactParameter(
				TrackErrorRequest::class,
				'trackError',
				function ($className, $params = [])
				{
					return new $className($this->getSourceParametersList()[0]);
				}
			),
			new ExactParameter(
				ControllerRequest::class,
				'callRequest',
				function ($className, $params = [])
				{
					return new $className($this->getSourceParametersList()[0]);
				}
			),
		], parent::getAutoWiredParameters());
	}

	/**
	 * @restMethod call.CallController.finishCall
	 */
	public function finishCallAction(ControllerRequest $callRequest): ?array
	{
		Loader::includeModule('im');

		$call = CallFactory::searchActiveByUuid(Call::PROVIDER_BITRIX, $callRequest->callUuid);
		if (!isset($call))
		{
			$this->addError(new Error(Error::CALL_NOT_FOUND));

			return null;
		}

		$call->finish();

		return ['result' => true];
	}

	/**
	 * @restMethod call.CallController.disconnectUser
	 */
	public function disconnectUserAction(ControllerRequest $callRequest): ?array
	{
		Loader::includeModule('im');

		$call = CallFactory::searchActiveByUuid(Call::PROVIDER_BITRIX, $callRequest->callUuid);
		if (!isset($call))
		{
			$this->addError(new Error(Error::CALL_NOT_FOUND));

			return null;
		}

		$callUser = $call->getUser($callRequest->userId);
		if ($callUser)
		{
			$callUser->updateState(CallUser::STATE_IDLE);
		}

		$call->getSignaling()->sendHangup($callRequest->userId, $call->getUsers(), null);

		return ['result' => true];
	}

	/**
	 * @restMethod call.CallController.trackReady
	 */
	public function trackReadyAction(TrackFileRequest $trackFile): ?array
	{
		Loader::includeModule('im');

		if ($log = CallAISettings::isLoggingEnable())
		{
			$logger = Logger::getInstance();
		}

		$call = CallFactory::searchActiveByUuid(Call::PROVIDER_BITRIX, $trackFile->callUuid);
		if (!isset($call))
		{
			$log && $logger->error("Call uuid:{$trackFile->callUuid} not found");
			$this->addError(new Error(Error::CALL_NOT_FOUND));
			return null;
		}

		if (!$call->isAiAnalyzeEnabled())
		{
			$log && $logger->error("Ignoring track:{$trackFile->url}, track #{$trackFile->trackId}. FollowUp was disabled for call #{$call->getId()}.");
			$this->addError(new CallAIError(CallAIError::AI_RECORDING_DISABLED));
			return null;
		}

		$minDuration = CallAISettings::getRecordMinDuration();
		if ($call->getDuration() < $minDuration)
		{
			$log && $logger->error("Ignoring track:{$trackFile->url}, track #{$trackFile->trackId}. Call #{$call->getId()} was too short.");
			$this->addError(new CallAIError(CallAIError::AI_RECORD_TOO_SHORT));

			$call
				->disableAudioRecord()
				->disableAiAnalyze()
				->save();

			CallAIService::getInstance()->removeExpectation($call->getId());

			(new FollowUpAnalytics($call))->addGotEmptyRecord();

			return null;
		}

		$trackList = CallTrackTable::query()
			->setSelect(['ID'])
			->where('CALL_ID', $call->getId())
			->where('EXTERNAL_TRACK_ID', $trackFile->trackId)
			->setLimit(1)
			->exec()
		;
		if ($trackList->getSelectedRowsCount() > 0)
		{
			$log && $logger->error("Ignoring track:{$trackFile->url}, track #{$trackFile->trackId}. Got duplicate request for call #{$call->getId()}");
			$this->addError(new Error(TrackError::TRACK_DUPLICATE_ERROR));
			return null;
		}

		$track = (new \Bitrix\Call\Track)
			->setCallId($call->getId())
			->setExternalTrackId($trackFile->trackId)
			->setDownloadUrl($trackFile->url)
		;

		if (in_array($trackFile->type, [\Bitrix\Call\Track::TYPE_TRACK_PACK, \Bitrix\Call\Track::TYPE_RECORD], true))
		{
			$track->setType($trackFile->type);
		}

		$mime = \Bitrix\Main\Web\MimeType::normalize($trackFile->mime);
		if ($mime)
		{
			$track->setFileMimeType($mime);
		}

		if ($trackFile->name)
		{
			$track->setFileName($trackFile->name);
		}
		$track->generateFilename();

		if ($trackFile->duration)
		{
			$track->setDuration($trackFile->duration);
		}

		if ($trackFile->size)
		{
			$track->setFileSize($trackFile->size);
		}

		$saveResult = $track->save();
		if (!$saveResult->isSuccess())
		{
			$log && $logger->error("Save track error: ".implode('; ', $saveResult->getErrorMessages()));
			$this->addErrors($saveResult->getErrors());
			return null;
		}

		(new FollowUpAnalytics($call))
			->sendTelemetry(
				source: null,
				status: 'success',
				event: 'track_ready_'.mb_strtolower($track->getType()),
			)
		;

		$trackService = TrackService::getInstance();

		if ($track->getType() === \Bitrix\Call\Track::TYPE_TRACK_PACK)
		{
			$processResult = $trackService->processTrack($track);
			if (!$processResult->isSuccess())
			{
				$this->addErrors($processResult->getErrors());

				$error = $processResult->getError();
				(new FollowUpAnalytics($call))
					->sendTelemetry(
						source: null,
						status: 'error',
						event: 'track_processing_error',
						error: $error
					);
			}
		}

		if ($trackService->doNeedDownloadTrack($track))
		{
			$downloadResult = $trackService->downloadTrackFile($track, true);
			if (!$downloadResult->isSuccess())
			{
				$this->addErrors($downloadResult->getErrors());
				return null;
			}

			// Check if download is still in progress (chunked download)
			$downloadData = $downloadResult->getData();
			if (isset($downloadData['status']) && $downloadData['status'] === 'in_progress')
			{
				// Download will continue via agent
				return ['result' => true, 'status' => 'in_progress'];
			}
		}
		else
		{
			// Track already downloaded, process it directly
			$processResult = $trackService->processTrack($track);
			if (!$processResult->isSuccess())
			{
				$this->addErrors($processResult->getErrors());

				$error = $processResult->getError();
				(new FollowUpAnalytics($call))
					->sendTelemetry(
						source: null,
						status: 'error',
						event: 'track_processing_error',
						error: $error
					)
				;
			}
		}

		// Update AI agent expectation time when track is ready
		if ($call->isAiAnalyzeEnabled())
		{
			CallAIService::getInstance()->updateExpectationTime($call->getId());
			$log && $logger->info("Updated AI expectation time for call #{$call->getId()} after receiving track");
		}

		return ['result' => true];
	}

	/**
	 * @restMethod call.CallController.trackError
	 */
	public function trackErrorAction(TrackErrorRequest $trackError): ?array
	{
		Loader::includeModule('im');

		if ($log = CallAISettings::isLoggingEnable())
		{
			$logger = Logger::getInstance();
		}

		$call = CallFactory::searchActiveByUuid(Call::PROVIDER_BITRIX, $trackError->callUuid);
		if (!isset($call))
		{
			$log && $logger->error("Call uuid:{$trackError->callUuid} not found");
			$this->addError(new Error(Error::CALL_NOT_FOUND));
			return null;
		}

		$log && $logger->error("Got track error: ".($trackError->errorCode ?? '-'));

		// Remove AI expectation agent for failed track recording
		CallAIService::getInstance()->removeExpectation($call->getId());
		$log && $logger->info("Removed AI expectation agent for call #{$call->getId()} due to track error: ".($trackError->errorCode ?? '-'));

		$call
			->disableAudioRecord()
			->disableAiAnalyze()
			->save();

		$call->getSignaling()
			->sendSwitchTrackRecordStatus(0, false, $trackError->errorCode);

		(new FollowUpAnalytics($call))->addErrorRecording($trackError->errorCode);

		return ['result' => true];
	}

	/**
	 * @restMethod call.CallController.checkPublicUrl
	 */
	public function checkPublicUrlAction(int $ping): ?array
	{
		if (CallAISettings::isLoggingEnable())
		{
			$logger = Logger::getInstance();
			$logger->info("Got checkPublicUrl action");
		}

		return ['pong' => ++$ping];
	}
}

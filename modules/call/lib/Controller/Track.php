<?php

namespace Bitrix\Call\Controller;

use Bitrix\Main\Loader;
use Bitrix\Main\Engine;
use Bitrix\Main\Engine\Response\BFile;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Service\Context;
use Bitrix\Im\V2\Message\Send\SendingConfig;
use Bitrix\Call\Call;
use Bitrix\Call\Call\Registry;
use Bitrix\Call\Error;
use Bitrix\Call\Settings;
use Bitrix\Call\NotifyService;
use Bitrix\Call\Track\TrackCollection;
use Bitrix\Call\Model\CallTrackTable;
use Bitrix\Call\ControllerClient;
use Bitrix\Call\Integration\AI\ChatMessage;
use Bitrix\Call\Integration\AI\CallAIService;
use Bitrix\Call\Integration\AI\CallAISettings;
use Bitrix\Call\Cache\ExternalAccessTokenManager;


/**
 * @internal
 */
class Track extends Engine\Controller
{
	public function configureActions(): array
	{
		return [
			'download' => [
				'prefilters' => [
					new Engine\ActionFilter\CloseSession(),
					new Engine\ActionFilter\HttpMethod([Engine\ActionFilter\HttpMethod::METHOD_GET])
				],
			],
		];
	}

	protected function init(): void
	{
		parent::init();
		Loader::includeModule('call');
		Loader::includeModule('im');
	}

	/**
	 * @restMethod call.Track.list
	 * @return array<array>|null
	 */
	public function listAction(): ?array
	{
		$call = $this->getCall();
		if (!$call)
		{
			return null;
		}

		/** @var TrackCollection $trackList */
		$trackList = CallTrackTable::query()
			->setSelect(['*'])
			->where('CALL_ID', $call->getId())
			->where('TYPE', \Bitrix\Call\Track::TYPE_RECORD)
			->setOrder(['ID' => 'DESC'])
			->exec()
			->fetchCollection()
		;

		return $trackList->toRestFormat();
	}

	/**
	 * @restMethod call.Track.get
	 * @param int $callId
	 * @param int $trackId
	 * @return array|null
	 */
	public function getAction(int $callId, int $trackId): ?array
	{
		$track = $this->getTrack($callId, $trackId);
		if (!$track)
		{
			return null;
		}

		return $track->toRestFormat();
	}

	/**
	 * @restMethod call.Track.drop
	 * @param int $callId
	 * @param int $trackId
	 * @return array|null
	 */
	public function dropAction(int $callId, int $trackId): ?array
	{
		$track = $this->getTrack($callId, $trackId);
		if (!$track)
		{
			return null;
		}

		$deletedCallId = $track->getCallId();
		$deletedPack = $track->getType() === \Bitrix\Call\Track::TYPE_TRACK_PACK;

		$result = $track->drop();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
			return null;
		}

		// A pack deleted by hand leaves the wait for the follow-up with nothing but that very file
		// to go and ask the service for, and taking it back would pay for a new analysis of a
		// recording the user has just removed {@see CallAIService::takeTracksFromService()}. The
		// service keeps the file either way: it answers this deletion without touching the state it
		// reports about it.
		//
		// Only for a pack, and only while none is left: the analysis is a separate result of the
		// call, so a deleted audio record must not cost it its timeout and its retry button. That
		// record does not come back either - the portal takes no file it has already downloaded
		// {@see CallAIService::isDeliverableServiceFile()}.
		if ($deletedPack && !\Bitrix\Call\Track::getTrackForCall($deletedCallId, \Bitrix\Call\Track::TYPE_TRACK_PACK))
		{
			CallAIService::getInstance()->removeExpectation($deletedCallId);
		}

		return $track->toRestFormat();
	}

	/**
	 * @restMethod call.Track.destroy
	 * @return array|null
	 */
	public function destroyAction(): ?array
	{
		$call = $this->getCall();
		if (!$call)
		{
			return null;
		}

		// The portal answers this deletion with success, so the portal remembers it: nothing else
		// can. The modern scheme below tells the media service nothing about the destroy, and the
		// legacy one answers success whether or not the service accepted it - while the entry
		// points that ask the service for the files of the call live on: the wait of the follow-up,
		// the button of the failure message in the chat and the public restart. They read the mark
		// before asking {@see CallAIService::askServiceForFiles()}, and the intake refuses a file
		// of a marked call whoever brings it {@see \Bitrix\Call\Track\TrackService::intakeServiceFile()}.
		//
		// The mark goes first, ahead of the save and of the request to the service: the legacy
		// branch waits on that request for seconds, and a file arriving within that wait - pushed
		// in by the service {@see \Bitrix\Call\Controller\CallController::trackReadyAction()} or
		// asked for by the wait of the follow-up - would read a table that still says the record
		// is alive and would take the very file the user is deleting. Nothing here undoes the mark
		// on a failure: this action answers success in every branch, so a mark left by a deletion
		// that broke halfway is exactly the answer the user got.
		$call->markRecordDestroyed();

		if (Settings::isNewCallsEnabled())
		{
			$call
				->setActionUserId($this->getCurrentUser()->getId())
				->disableAudioRecord()
				->disableAiAnalyze()
				->save()
			;
		}
		else
		{
			$call
				->setActionUserId($this->getCurrentUser()->getId())
				->disableAudioRecord()
				->disableAiAnalyze()
				->save()
			;

			$this->sendSwitchTrackRecordStatus($call, false);

			(new ControllerClient)->destroyTrack($call);
		}

		// The wait for the follow-up goes with the record. Its branch for a switched-off analysis
		// asks the service for the audio record of the call and delivers it, and the record a user
		// has just destroyed must not come back to the chat that way
		// {@see CallAIService::closeExpectationWithoutAnalysis()}. The flag alone cannot tell a
		// technical switch-off from this deletion, so the wait is ended where the intent is known.
		CallAIService::getInstance()->removeExpectation($call->getId());

		Loader::includeModule('im');

		$chat = Chat::getInstance($call->getChatId());
		$message = ChatMessage::generateTrackDestroyMessage($call->getId(), $this->getCurrentUser()->getId(), $chat);
		if ($message)
		{
			$message->setAuthorId($call->getInitiatorId());
			$sendingConfig = (new SendingConfig())
				->enableSkipCounterIncrements()
				->enableSkipUrlIndex()
			;
			$context = (new Context())->setUser($call->getInitiatorId());
			NotifyService::getInstance()->sendMessageDeferred($chat, $message, $sendingConfig, $context);
		}

		return ['destroyed' => true];
	}



	/**
	 * @restMethod call.Track.start
	 * @return array|null
	 */
	public function startAction(): ?array
	{
		$call = $this->getCall();
		if (!$call)
		{
			return null;
		}

		// A finished call records nothing, so there is nothing to start - and this action must not be
		// the way back to a record its user destroyed. In the modern scheme it tells the media
		// service nothing at all: it only puts the flags of the call up and lifts the mark of the
		// deletion below, which would hand the files the service still keeps back to the retry
		// {@see CallAIService::retryCallAiTask()}. Ahead of the flags and of the mark for that
		// reason {@see Call::liftRecordDestroyedMark()}.
		if ($call->getState() === Call::STATE_FINISHED)
		{
			$this->addError(new Error('call_finished', 'The call is finished'));

			return null;
		}

		$aiAvailability = CallAISettings::checkAIAvailabilityInCall();
		if (!$aiAvailability->isSuccess())
		{
			$this->addError($aiAvailability->getError());
			NotifyService::getInstance()->sendCallError($aiAvailability->getError(), $call);

			return null;
		}

		if (Settings::isNewCallsEnabled())
		{
			$call
				->setActionUserId($this->getCurrentUser()->getId())
				->enableAudioRecord()
				->enableAiAnalyze()
				->save()
			;
		}
		else
		{
			$result = (new ControllerClient)->startTrack($call);
			if (!$result->isSuccess())
			{
				$this->addErrors($result->getErrors());
				return null;
			}

			$call
				->setActionUserId($this->getCurrentUser()->getId())
				->enableAudioRecord()
				->enableAiAnalyze()
				->save()
			;

			$this->sendSwitchTrackRecordStatus($call, true);
		}

		// After the start is settled: the call records again, so the files of that recording are
		// the files of a recording nobody has removed {@see Call::liftRecordDestroyedMark()}.
		$call->liftRecordDestroyedMark();

		return ['started' => true];
	}

	/**
	 * @restMethod call.Track.stop
	 * @return array|null
	 */
	public function stopAction(): ?array
	{
		$call = $this->getCall();
		if (!$call)
		{
			return null;
		}

		if (Settings::isNewCallsEnabled())
		{
			$call
				->setActionUserId($this->getCurrentUser()->getId())
				->disableAudioRecord()
				->save()
			;
		}
		else
		{
			$result = (new ControllerClient)->stopTrack($call);
			if (!$result->isSuccess())
			{
				$this->addErrors($result->getErrors());
				return null;
			}

			$call
				->setActionUserId($this->getCurrentUser()->getId())
				->disableAudioRecord()
				->save()
			;

			$this->sendSwitchTrackRecordStatus($call, false);
		}

		return ['stopped' => true];
	}

	/**
	 * @restMethod call.Track.download
	 * @param string $signedParameters
	 * @return BFile|null
	 */
	public function downloadAction(string $signedParameters): ?BFile
	{
		$params = $this->decodeSignedParameters($signedParameters);
		$callId = (int)$params['callId'];
		$trackId = (int)$params['trackId'];
		$forceDownload = (bool)($params['forceDownload'] ?? false);
		$token = strval($params['token']) ?? '';

		$call = Registry::getCallWithId($callId);
		if (!$call)
		{
			\Bitrix\Main\Context::getCurrent()->getResponse()->setStatus(404);
			$this->addError(new Error("call_not_found", "Call not found"));
			return null;
		}

		if (!empty($token))
		{
			$tokenData = ExternalAccessTokenManager::validateToken($token);
			if (!$tokenData || $tokenData['track_id'] != $trackId || $tokenData['call_id'] != $callId)
			{
				\Bitrix\Main\Context::getCurrent()->getResponse()->setStatus(403);
				$this->addError(new Error("invalid_token", "Invalid or expired token"));
				return null;
			}
			//ExternalAccessTokenManager::revokeToken($token);
		}
		else
		{
			$currentUserId = $this->getCurrentUser()?->getId();
			if (!$currentUserId)
			{
				\Bitrix\Main\Context::getCurrent()->getResponse()->setStatus(403);
				$this->addError(new Error("access_denied", "User not found"));
				return null;
			}

			if (!$this->checkCallAccess($call, $currentUserId))
			{
				\Bitrix\Main\Context::getCurrent()->getResponse()->setStatus(403);
				return null;
			}
		}

		$track = TrackCollection::getTrackById($callId, $trackId);
		if (!$track)
		{
			\Bitrix\Main\Context::getCurrent()->getResponse()->setStatus(403);
			$this->addError(new Error("track_not_found", "Track not found"));
			return null;
		}

		if (!$track->getFileId())
		{
			\Bitrix\Main\Context::getCurrent()->getResponse()->setStatus(404);
			$this->addError(new Error("track_file_not_found", "Track file not found"));
			return null;
		}

		try
		{
			return BFile::createByFileId($track->getFileId(), $track->getFileName())->showInline(!$forceDownload);
		}
		catch (\Bitrix\Main\ObjectNotFoundException $e)
		{
			\Bitrix\Main\Context::getCurrent()->getResponse()->setStatus(404);
			$this->addError(new Error($e->getMessage(), $e->getCode()));
			return null;
		}
	}


	protected function getTrack(int $callId, int $trackId): ?\Bitrix\Call\Track
	{
		$call = $this->getCall($callId);
		if (!$call)
		{
			return null;
		}

		$track = TrackCollection::getTrackById($call->getId(), $trackId);
		if (!$track)
		{
			$this->addError(new Error("track_not_found", "Track not found"));
			return null;
		}

		return $track;
	}


	protected function getCall(): ?\Bitrix\Call\Call
	{
		if ($this->getRequest()->isPost())
		{
			$sourceParametersList = $this->getSourceParametersList()[0];
		}
		else
		{
			$sourceParametersList = $this->getSourceParametersList()[1];
		}

		$call = null;
		if (!empty($sourceParametersList['callUuid']))
		{
			$call = Registry::getCallWithUuid((string)$sourceParametersList['callUuid']);
		}
		elseif (!empty($sourceParametersList['callId']) && is_numeric($sourceParametersList['callId']))
		{
			$call = Registry::getCallWithId((int)$sourceParametersList['callId']);
		}
		if (!$call)
		{
			$this->addError(new Error("call_not_found", "Call not found"));
			return null;
		}

		$currentUserId = $this->getCurrentUser()?->getId();
		if (!$currentUserId || !$this->checkCallAccess($call, $currentUserId))
		{
			$this->addError(new Error("access_denied", "You do not have access to this call"));
			return null;
		}

		return $call;
	}


	protected function checkCallAccess(Call $call, int $userId): bool
	{
		if (!$call->checkAccess($userId))
		{
			$this->addError(new Error('access_denied', "You don't have access to the call " . $call->getId() . "; (current user id: " . $userId . ")"));
			return false;
		}

		return true;
	}

	protected function sendSwitchTrackRecordStatus(Call $call, bool $isTrackRecordOn)
	{
		$currentUserId = $this->getCurrentUser()?->getId();
		if (!$currentUserId || !$this->checkCallAccess($call, $currentUserId))
		{
			$this->addError(new Error("access_denied", "You do not have access to this call"));
			return null;
		}
		$call->getSignaling()->sendSwitchTrackRecordStatus($currentUserId, $isTrackRecordOn);
	}

	/**
	 * Sings and stores parameters.
	 * @param string $signedParameters Signed parameters of component as string.
	 * @return array
	 */
	protected function decodeSignedParameters(string $signedParameters): array
	{
		return \Bitrix\Main\Component\ParameterSigner::unsignParameters('call.Track.download', $signedParameters);
	}
}

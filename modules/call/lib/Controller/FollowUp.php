<?php

namespace Bitrix\Call\Controller;

use Bitrix\Main\Loader;
use Bitrix\Main\Engine;
use Bitrix\Main\Web\Json;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Engine\ActionFilter\Scope;
use Bitrix\Main\Localization\Loc;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Params;
use Bitrix\Im\Call\Call;
use Bitrix\Im\Call\Registry;
use Bitrix\Call\Error;
use Bitrix\Call\NotifyService;
use Bitrix\Call\Track\TrackCollection;
use Bitrix\Call\Integration\AI\CallAIService;
use Bitrix\Call\Integration\AI\ChatEventLog;
use Bitrix\Call\Integration\AI\ChatMessage;
use Bitrix\Call\Integration\AI\Outcome\Transcription;
use Bitrix\Call\Integration\AI\Outcome\OutcomeCollection;
use Bitrix\Call\Model\CallTrackTable;
use Bitrix\Call\Model\CallAITaskTable;
use Bitrix\Call\Model\CallOutcomePropertyTable;

class FollowUp extends Engine\Controller
{
	protected function init(): void
	{
		parent::init();
		Loader::includeModule('call');
		Loader::includeModule('im');
	}

	/**
	 * @inheritDoc
	 */
	public function configureActions()
	{
		return [
			'drop' => [
				'+prefilters' => [new Scope(Scope::AJAX)]
			],
			'info' => [
				'+prefilters' => [new Scope(Scope::AJAX)]
			],
			'outcome' => [
				'+prefilters' => [new Scope(Scope::AJAX)]
			],
			'debugOn' => [
				'+prefilters' => [new Scope(Scope::AJAX)]
			],
			'debugOff' => [
				'+prefilters' => [new Scope(Scope::AJAX)]
			],
			'clearDebug' => [
				'+prefilters' => [new Scope(Scope::AJAX)]
			],
		];
	}

	protected function canDeleteFollowUp(Call $call, int $userId): bool
	{
		$roles = $call->getUserRoles([$userId]);
		if (isset($roles[$userId]) && in_array($roles[$userId], ['ADMIN', 'MANAGER'], true))
		{
			return true;
		}

		$this->addError(new Error('access_denied', "You do not have access to this action"));

		return false;
	}

	/**
	 * @restMethod call.FollowUp.drop
	 * @param int $callId
	 * @return array|null
	 */
	public function dropAction(int $callId): ?array
	{
		$call = $this->getCall($callId);
		if (!$call)
		{
			return null;
		}

		if (!$this->canDeleteFollowUp($call, $this->getCurrentUser()->getId()))
		{
			return null;
		}

		$result = CallAIService::getInstance()->dropCallAiFollowUp($call->getId());
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
			return null;
		}

		if ($call->getChatId())
		{
			$messages = NotifyService::getInstance()->findMessagesForCall($call->getChatId(), $callId, 1000);

			/** @var Message $message */
			foreach ($messages as $message)
			{
				$messageType = $message->getParams()->get(Params::COMPONENT_PARAMS)->getValue()['MESSAGE_TYPE'] ?? '';
				switch ($messageType)
				{
					case NotifyService::MESSAGE_TYPE_AI_OVERVIEW:
						$chat = Chat::getInstance($call->getChatId());
						$droppedMessage = ChatMessage::generateFollowUpDroppedMessage($callId, $this->getCurrentUser()->getId(), $chat);

						$message->getParams()->remove(Params::ATTACH);
						$message->setMessage($droppedMessage->getMessage());
						$message->save();

						$chat->sendPushUpdateMessage($message);
						break;

					case NotifyService::MESSAGE_TYPE_AI_FAILED:
					case NotifyService::MESSAGE_TYPE_AI_INFO:
						$message->deleteComplete();
						break;
				}
			}
		}

		return ['dropped' => 'ok'];
	}

	/**
	 * @restMethod call.FollowUp.info
	 * @param int $callId
	 * @return array<array>|null
	 */
	public function infoAction(int $callId): ?array
	{
		if ($this->getScope() !== static::SCOPE_AJAX)
		{
			$this->addError(new Error('wrong_scope', 'Scope is not supported'));
			return null;
		}

		$call = $this->getCall($callId);
		if (!$call)
		{
			return null;
		}

		$result = [];

		$result['CALL'] = [
			'ID' => $call->getId(),
			'TYPE' => $call->getType(),
			'INITIATOR_ID' => $call->getInitiatorId(),
			'PROVIDER' => $call->getProvider(),
			'STATE' => $call->getState(),
			'START_DATE' => $call->getStartDate(),
			'END_DATE' => $call->getEndDate(),
			'UUID' => $call->getUuid(),
			'RECORD_AUDIO' => $call->isAudioRecordEnabled(),
			'AI_ANALYZED' => $call->isAiAnalyzeEnabled(),
		];

		$trackList = CallTrackTable::query()
			->setSelect([
				'ID',
				'TYPE',
				'FILE_ID',
				'DISK_FILE_ID',
				'DURATION',
				'FILE_SIZE',
				'FILE_NAME',
				'FILE_MIME_TYPE',
				'CALL_ID',
			])
			->where('CALL_ID', $call->getId())
			->setOrder(['ID' => 'DESC'])
			->exec()
		;
		$result['TRACKS'] = [];
		while ($track = $trackList->fetchObject())
		{
			$result['TRACKS'][] = array_merge(
				$track->toArray(),
				[
					'DOWNLOAD_URL' => $track->getDownloadUrl(),
					'EXTERNAL_URL' => $track->getUrl(true, false, true),
				]
			);
		}

		$taskList = CallAITaskTable::query()
			->setSelect([
				'ID',
				'TYPE',
				'CALL_ID',
				'HASH',
				'TRACK_ID',
				'STATUS',
				'ERROR_CODE',
				'ERROR_MESSAGE',
				'DATE_CREATE',
				'DATE_FINISHED',
			])
			->where('CALL_ID', $call->getId())
			->setOrder(['ID' => 'DESC'])
			->exec()
		;
		$result['AI_TASK'] = [];
		while ($task = $taskList->fetchObject())
		{
			$result['AI_TASK'][] = [
				'ID' => $task->getId(),
				'TYPE' => $task->getType(),
				'CALL_ID' => $task->getCallId(),
				'HASH' => $task->getHash(),
				'TRACK_ID' => $task->getTrackId(),
				'STATUS' => $task->getStatus(),
				'ERROR_CODE' => $task->getErrorCode(),
				'ERROR_MESSAGE' => $task->getErrorMessage(),
				'DATE_CREATE' => $task->getDateCreate(),
				'DATE_FINISHED' => $task->getDateFinished(),
			];
		}

		$outcomeList = CallOutcomePropertyTable::query()
			->setSelect([
				'OUTCOME_ID' => 'OUTCOME_ID',
				'TYPE' => 'OUTCOME.TYPE',
				'TRACK_ID' => 'OUTCOME.TRACK_ID',
				'DATE_CREATE' => 'OUTCOME.DATE_CREATE',
				'CODE' => 'CODE',
				'CONTENT' => 'CONTENT',
			])
			->where('OUTCOME.CALL_ID', $call->getId())
			->setOrder(['ID' => 'DESC'])
			->exec()
		;

		$result['AI_OUTCOME'] = [];
		while ($outcome = $outcomeList->fetch())
		{
			try
			{
				$jsonData = Json::decode($outcome['CONTENT']);
			}
			catch (ArgumentException)
			{
				$jsonData = htmlspecialcharsbx($outcome['CONTENT']);
			}
			$result['AI_OUTCOME'][] = [
				'OUTCOME_ID' => $outcome['OUTCOME_ID'],
				'TRACK_ID' => $outcome['TRACK_ID'],
				'TYPE' => $outcome['TYPE'],
				'DATE_CREATE' => $outcome['DATE_CREATE'],
				'CODE' => $outcome['CODE'],
				'CONTENT' => $jsonData,
			];
		}

		return $result;
	}

	/**
	 * @restMethod call.FollowUp.outcome
	 * @param int $callId
	 * @return array<array>|null
	 */
	public function outcomeAction(int $callId): ?array
	{
		$call = $this->getCall($callId);
		if (count($this->getErrors()) > 0)
		{
			return null;
		}

		$currentUserId = $this->getCurrentUser()?->getId() ?? null;
		if (!$this->checkCallAccess($call, $currentUserId))
		{
			return null;
		}

		/** @var Transcription $transcription */
		$transcription = null;
		$outcomes = ['version' => 1];
		$outcomeCollection = OutcomeCollection::getOutcomesByCallId($callId) ?? [];
		foreach ($outcomeCollection as $outcome)
		{
			$content = $outcome->getSenseContent();
			if ($content)
			{
				if ($content instanceof Transcription)
				{
					$transcription = $content;
				}
				$outcomes[$outcome->getType()] = $content->toRestFormat(mentionFormat: 'bb');
				$outcomes['version'] = max($outcomes['version'], $content->getVersion());
			}
		}

		if (
			$outcomes['insights']['speakerEvaluationAvailable']
			&& !empty($outcomes['insights']['speakerAnalysis'])
		)
		{
			$speakerList = $transcription->prepareSpeakersList();
			$speakerAnalysis = [];
			/** @var array{userId: int, efficiencyValue: float} $speaker */
			foreach ($outcomes['insights']['speakerAnalysis'] as $speaker)
			{
				if (!isset($speakerList[$speaker['userId']]))
				{
					continue;
				}
				$speaker['talkPercentage'] = $speakerList[$speaker['userId']]['talkPercentage'];
				$speaker['duration'] = (int)$speakerList[$speaker['userId']]['duration'];
				$speaker['durationFormat'] = $this->formatLength((int)$speakerList[$speaker['userId']]['duration']);
				$speakerAnalysis[] = $speaker;
			}

			// sort users by efficiencyValue and talkPercentage
			\Bitrix\Main\Type\Collection::sortByColumn(
				array: $speakerAnalysis,
				columns: [
					'talkPercentage' => \SORT_DESC,
					'efficiencyValue' => \SORT_DESC,
				]
			);
			$outcomes['insights']['speakerAnalysis'] = $speakerAnalysis;
		}

		$result = [
			'call' => $this->prepareCallData($call),
			'tracks' => $this->prepareTracksData($call->getId()),
			'aiOutcome' => $outcomes,
		];

		return ['result' => $result];
	}

	/**
	 * Setup session flag to enable record all user's call.
	 *
	 * @restMethod call.FollowUp.debugOn
	 * @param int $chatId
	 * @return array|null
	 */
	public function debugOnAction(int $chatId): ?array
	{
		$chat = Chat::getInstance($chatId);
		if (!$chat->getChatId())
		{
			$this->addError(new Error("chat_not_found", "Call chat not found"));
			return null;
		}

		$currentUserId = $this->getCurrentUser()?->getId();
		if (!$currentUserId || !$chat->checkAccess($currentUserId))
		{
			$this->addError(new Error('access_denied', "You do not have access to this call chat"));
			return null;
		}

		ChatEventLog::chatDebugEnable($chat->getChatId());

		return ['debug.mode' => 'on'];
	}

	/**
	 * Removes session flag that is enabled record all user's call.
	 *
	 * @restMethod call.FollowUp.debugOff
	 * @param int $chatId
	 * @return array|null
	 */
	public function debugOffAction(int $chatId): ?array
	{
		$chat = Chat::getInstance($chatId);
		if (!$chat->getChatId())
		{
			$this->addError(new Error("chat_not_found", "Call chat not found"));
			return null;
		}

		$currentUserId = $this->getCurrentUser()?->getId();
		if (!$currentUserId || !$chat->checkAccess($currentUserId))
		{
			$this->addError(new Error('access_denied', "You do not have access to this call chat"));
			return null;
		}

		ChatEventLog::chatDebugDisable($chat->getChatId());

		return ['debug.mode' => 'off'];
	}

	/**
	 * @restMethod call.FollowUp.debugClear
	 * @param int $callId
	 * @return array|null
	 */
	public function debugClearAction(int $callId): ?array
	{
		$call = $this->getCall($callId);
		if (!$call)
		{
			return null;
		}

		if ($call->getChatId())
		{
			$messages = NotifyService::getInstance()->findMessagesForCall($call->getChatId(), $callId, 1000);
			foreach ($messages as $message)
			{
				$messageType = $message->getParams()->get(Params::COMPONENT_PARAMS)->getValue()['MESSAGE_TYPE'] ?? '';
				if ($messageType === NotifyService::MESSAGE_TYPE_AI_INFO)
				{
					$message->deleteComplete();
				}
			}
		}

		return ['debug.cleaned' => 'ok'];
	}

	/**
	 * @param int $callId
	 * @return Call|null
	 */
	protected function getCall(int $callId): ?\Bitrix\Im\Call\Call
	{
		$call = Registry::getCallWithId($callId);
		if (!$call)
		{
			$this->addError(new Error("call_not_found", "Call not found"));
			return null;
		}

		$currentUserId = $this->getCurrentUser()?->getId();
		if (!$currentUserId || !$call->checkAccess($currentUserId))
		{
			$this->addError(new Error('access_denied', "You do not have access to this call"));
			return null;
		}

		return $call;
	}

	/**
	 * @param int $userId
	 * @param Call $call
	 * @return bool
	 */
	protected function checkCallAccess(Call $call, ?int $userId = null): bool
	{
		if (!$userId || !$call->checkAccess($userId))
		{
			$this->addError(new Error('access_denied', "You don't have access to the call " . $call->getId() . "; (current user id: " . $userId . ")"));
			return false;
		}

		return true;
	}

	private function prepareCallData(Call $call): array
	{
		return [
			'id' => $call->getId(),
			'type' => $call->getType(),
			'initiatorId' => $call->getInitiatorId(),
			'provider' => $call->getProvider(),
			'state' => $call->getState(),
			'startDate' => $call->getStartDate(),
			'endDate' => $call->getEndDate(),
			'uuid' => $call->getUuid(),
			'recordAudio' => $call->isAudioRecordEnabled(),
		];
	}

	private function prepareTracksData(int $callId): array
	{
		$tracks = [];
		$trackCollection = TrackCollection::getRecordings($callId) ?? [];
		foreach ($trackCollection as $track)
		{
			$tracks[] = $track->toRestFormat();
		}

		return $tracks;
	}

	private function formatLength(int $duration): string
	{
		$hours = $minutes = $seconds = 0;
		if ($duration < 60)
		{
			$seconds = $duration;
		}
		else
		{
			$hours = floor($duration / 3600);
			if ($hours > 0)
			{
				$duration -= $hours * 3600;
			}
			$minutes = floor($duration / 60);
		}

		return $this->formatInterval($hours, $minutes, $seconds);
	}

	private function formatInterval(int $hours = 0, int $minutes = 0, int $seconds = 0): string
	{
		Loc::loadMessages($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/im/lib/call/integration/chat.php');

		$result = [];
		if ($hours > 0)
		{
			$result[] = Loc::getMessage('IM_CALL_INTEGRATION_CHAT_CALL_DURATION_HOURS', [
				'#HOURS#' => $hours
			]);
		}
		if ($minutes > 0)
		{
			$result[] = Loc::getMessage('IM_CALL_INTEGRATION_CHAT_CALL_DURATION_MINUTES', [
				'#MINUTES#' => $minutes
			]);
		}
		if ($seconds > 0 && !($hours > 0))
		{
			$result[] = Loc::getMessage('IM_CALL_INTEGRATION_CHAT_CALL_DURATION_SECONDS', [
				'#SECONDS#' => $seconds
			]);
		}

		return implode(' ', $result);
	}
}

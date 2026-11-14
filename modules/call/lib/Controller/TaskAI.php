<?php

namespace Bitrix\Call\Controller;

use Bitrix\Main\Loader;
use Bitrix\Main\Engine;
use Bitrix\Main\Web\Json;
use Bitrix\Main\ArgumentException;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Service\Context;
use Bitrix\Im\V2\Message\Send\SendingConfig;
use Bitrix\Call\Call;
use Bitrix\Call\Call\Registry;
use Bitrix\Call\Error;
use Bitrix\Call\NotifyService;
use Bitrix\Call\Integration\AI\ChatMessage;
use Bitrix\Call\Integration\AI\CallAIError;
use Bitrix\Call\Integration\AI\CallAIService;
use Bitrix\Call\Model\CallTrackTable;
use Bitrix\Call\Model\CallAITaskTable;
use Bitrix\Call\Model\CallOutcomePropertyTable;

/**
 * @internal
 */
class TaskAI extends Engine\Controller
{
	protected function init(): void
	{
		parent::init();
		Loader::includeModule('call');
		Loader::includeModule('im');
	}

	public function configureActions(): array
	{
		return [
			// Added to the default prefilters, not put in their place: an own 'prefilters' list
			// replaces them, and with it the Csrf filter, which the core then puts back only for a
			// request that already is a POST. The restart restores AI_ANALYZE, asks the service for
			// the files of the call and starts a paid analysis, so it stays out of reach of a
			// cross-site navigation (@see \Bitrix\Main\Engine\Controller::getDefaultPreFilters()).
			'restart' => [
				'+prefilters' => [
					new Engine\ActionFilter\HttpMethod([Engine\ActionFilter\HttpMethod::METHOD_POST]),
				],
			],
		];
	}

	/**
	 * @restMethod call.TaskAI.restart
	 * @param int $callId
	 * @return array|null
	 */
	public function restartAction(int $callId): ?array
	{
		$call = $this->getCall($callId);
		if (!$call)
		{
			return null;
		}

		// A caller with no portal user behind it has nothing to check access against; one that has
		// is answered by checkCallAccess(), which puts its own refusal into the collection. Saying
		// it twice would send the REST caller two errors of one code for one refusal.
		$currentUserId = $this->getCurrentUser()?->getId();
		if (!$currentUserId)
		{
			$this->addError(new Error('access_denied', 'You do not have access to this call'));

			return null;
		}

		if (!$this->checkCallAccess($currentUserId, $call))
		{
			return null;
		}

		$result = CallAIService::getInstance()->retryCallAiTask($call);
		if (!$result->isSuccess())
		{
			return $this->reportRestartFailure(
				$call,
				$result->getError(),
				(bool)($result->getData()[CallAIService::RESTART_LAUNCHED] ?? false),
			);
		}

		// Status comes from CallAIService::restartCallAiTask(), which derives it from the launch and
		// pending state of the call. A launch that left no queued task carries no status at all: the
		// service cannot tell a refused launch from a started one, and this action must not guess.
		$status = $result->getData()['status'] ?? null;
		if ($status === null)
		{
			return [];
		}

		if ($status === CallAIService::RESTART_STATUS_STARTED)
		{
			$chat = Chat::getInstance($call->getChatId());
			if (
				!NotifyService::getInstance()->isMessageShown($callId, NotifyService::MESSAGE_TYPE_AI_START)
				&& NotifyService::getInstance()->findMessage($chat->getId(), $callId, NotifyService::MESSAGE_TYPE_AI_START, 1) === null
			)
			{
				$message = ChatMessage::generateTaskStartMessage($callId, $chat);
				// Claim-before-send keyed by (callId, AI_START, attemptId): the attempt
				// discriminator (transcribe task id) lets a manual restart re-show its status.
				if (
					$message
					&& NotifyService::getInstance()->claim($callId, NotifyService::MESSAGE_TYPE_AI_START, CallAIService::getInstance()->getCurrentAnalysisAttemptId($callId))
				)
				{
					$sendingConfig = (new SendingConfig())
						->enableSkipCounterIncrements()
						->enableSkipUrlIndex()
					;
					$context = (new Context())->setUser($call->getInitiatorId());
					NotifyService::getInstance()->sendMessageDeferred($chat, $message, $sendingConfig, $context);
					NotifyService::getInstance()->setMessageShown($callId, NotifyService::MESSAGE_TYPE_AI_START);
				}
			}
		}

		return ['status' => $status];
	}

	/**
	 * The failure travels in the payload alone: the REST bridge reads the controller's error
	 * collection only for a null result, while a non-empty one would turn the AJAX envelope into
	 * `status: error` and hide this payload from the caller's success branch.
	 */
	private function reportRestartFailure(Call $call, ?\Bitrix\Main\Error $error, bool $anythingLaunched): array
	{
		NotifyService::getInstance()->sendTaskFailedMessage($error, $call, -1);

		$failureDetail = [
			'code' => $error?->getCode(),
			'message' => $error?->getMessage(),
		];

		// A queued task reports back on its own, so a batch that got one into the queue did not
		// start nothing: only no status at all stays true once that result arrives.
		if ($anythingLaunched)
		{
			return ['error' => $failureDetail];
		}

		// A destroyed recording answers with the same status as a missing one: both name a retry
		// that has no file to work from, and neither is a launch that failed. The retry refuses a
		// destroyed record before it asks the service at all
		// {@see CallAIService::retryCallAiTask()}.
		$recordingIsGone = in_array(
			$error?->getCode(),
			[CallAIError::TRACK_PACK_NOT_FOUND_ERROR, CallAIService::ERROR_RECORD_DESTROYED],
			true,
		);

		$status = $recordingIsGone
			? CallAIService::RESTART_STATUS_RECORDING_MISSING
			: CallAIService::RESTART_STATUS_LAUNCH_FAILED
		;

		return ['status' => $status, 'error' => $failureDetail];
	}

	/**
	 * @restMethod call.TaskAI.info
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

		$currentUserId = $this->getCurrentUser()?->getId();
		if (!$currentUserId || !$this->checkCallAccess($currentUserId, $call))
		{
			$this->addError(new Error('access_denied', "You do not have access to this call"));
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
			$result['TRACKS'][] = $track->toArray();
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

	protected function getCall(int $callId): ?\Bitrix\Call\Call
	{
		$call = Registry::getCallWithId($callId);
		if (!$call)
		{
			$this->addError(new Error("call_not_found", "Call not found"));
			return null;
		}

		return $call;
	}

	protected function checkCallAccess(int $userId, Call $call): bool
	{
		if (!$call->checkAccess($userId))
		{
			$this->addError(new Error('access_denied', "You don't have access to the call " . $call->getId() . "; (current user id: " . $userId . ")"));
			return false;
		}

		return true;
	}
}

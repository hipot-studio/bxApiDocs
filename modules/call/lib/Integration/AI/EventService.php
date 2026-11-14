<?php

namespace Bitrix\Call\Integration\AI;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Service\Context;
use Bitrix\Im\V2\Message\Send\SendingConfig;
use Bitrix\Call\Call\Registry;
use Bitrix\Call\Logger\Logger;
use Bitrix\Call\NotifyService;
use Bitrix\Call\Track\TrackDeletionService;
use Bitrix\Call\Integration\AI\Outcome\OutcomeCollection;
use Bitrix\Call\Analytics\FollowUpAnalytics;

/**
 * @internal
 */

class EventService
{
	/**
	 * @see \Bitrix\Call\Call::fireCallFinishedEvent
	 */
	public static function onCallFinished(\Bitrix\Main\Event $event): void
	{
		if (!CallAISettings::isCallAIEnable())
		{
			return;
		}

		$call = $event->getParameters()['call'] ?? null;

		if (
			$call instanceof \Bitrix\Call\Call
			&& $call->isAiAnalyzeEnabled()
		)
		{
			$minDuration = CallAISettings::getRecordMinDuration();
			if ($call->getDuration() < $minDuration)
			{
				$call
					->disableAudioRecord()
					->disableAiAnalyze()
					->save();

				(new FollowUpAnalytics($call))->addErrorRecording(CallAIError::RECORD_TOO_SHORT);

				return;
			}

			// Setup result waiting agent
			CallAIService::getInstance()->setupExpectation($call->getId());

			$chat = Chat::getInstance($call->getChatId());
			if ($chat->getId() == $call->getChatId())
			{
				$message = ChatMessage::generateCallFinishedMessage($call, $chat);
				// Claim-before-send on the shared (callId, AI_START) key unifies the three
				// AI_START senders (here, CallFollowupBot::onCommandAdd, TaskAI::restartAction):
				// only the first one across processes sends.
				if (
					$message
					&& NotifyService::getInstance()->claim($call->getId(), NotifyService::MESSAGE_TYPE_AI_START)
				)
				{
					$sendingConfig = (new SendingConfig())
						->enableSkipCounterIncrements()
						->enableSkipUrlIndex()
					;
					$context = (new Context())->setUser($call->getInitiatorId());
					self::logUnsentMessage(
						$call->getId(),
						NotifyService::MESSAGE_TYPE_AI_START,
						NotifyService::getInstance()->sendMessageDeferred($chat, $message, $sendingConfig, $context)
					);
					NotifyService::getInstance()->setMessageShown($call->getId(), NotifyService::MESSAGE_TYPE_AI_START);
				}
			}

			(new FollowUpAnalytics($call))->addStopRecording();
		}
	}

	/**
	 * @see CallAIService::fireCallAiTaskEvent
	 */
	public static function onCallAiTaskStart(\Bitrix\Main\Event $event): void
	{
		if (!CallAISettings::isCallAIEnable())
		{
			return;
		}

		/*
		$task = $event->getParameters()['task'] ?? null;

		if ($task instanceof \Bitrix\Call\Integration\AI\Task\TranscribeCallRecord)
		{
			$chat = Chat::getInstance($task->fillCall()->getChatId());

			$message = ChatMessage::generateTaskStartMessage($task->getCallId(), $chat);
			if ($message)
			{
				//$chat->sendMessage($message);
				$notifyService = \Bitrix\Call\NotifyService::getInstance();
				$notifyService->sendMessageDeferred($chat, $message);
			}
		}
		*/
	}

	/**
	 * The final message of the analysis is no longer sent from here. The result event is fired in
	 * the middle of the handler of the AI queue, before the chain of the attempt is built
	 * {@see CallAIService::onQueueTaskExecute()}, so a forced run reported itself over its own
	 * transcription - out of the new transcription and the texts of the run before it, two
	 * generations at once. The send moved to the end of that handler
	 * {@see CallAIService::completeAnalysis()}.
	 *
	 * The handler stays registered: portals installed before this version hold its registration,
	 * and dropping it is a migration of its own.
	 *
	 * @see CallAIService::fireCallOutcomeEvent
	 */
	public static function onCallAiTaskComplete(\Bitrix\Main\Event $event): void
	{
	}

	/**
	 * The final message of the analysis: everything the attempt has to show, in one message.
	 *
	 * Called once the analysis of the call has finished talking - the chain opened nothing past
	 * the result at hand and no task of the call is still running
	 * {@see CallAIService::completeAnalysis()}. That is the question this method does not ask;
	 * the one it does ask is "is there anything to show".
	 *
	 * Two values come with the call and neither is looked up here {@see CallAIService::completeAnalysis()}.
	 * $attemptId is the marker the message carries - the task whose result is being reported, so
	 * the message names the attempt its contents came from. $attemptKey is the attempt itself, and
	 * it is what both guards of the send are keyed on: the leaves of one chain finish side by side
	 * and each holds a task of its own, so a guard keyed on the marker would give every handler a
	 * key of its own and serialize nobody - two messages per attempt, and every one-shot effect
	 * behind the claim done twice. The key is read before the attempt is found complete, so a
	 * restart pressed in the window cannot move it either.
	 *
	 * A caller that names no key claims by the marker, which is what a single value always meant.
	 */
	public static function sendAnalysisResultMessage(
		Outcome $outcome,
		?string $attemptId,
		?string $attemptKey = null
	): void
	{
		$attemptKey ??= $attemptId;

		if (!CallAISettings::isCallAIEnable())
		{
			return;
		}

		$waitForTasks = array_column(SenseType::cases(), 'value');
		if (!in_array($outcome->getType(), $waitForTasks, true))
		{
			return;
		}

		$callId = $outcome->getCallId();
		$outcomeCollection = OutcomeCollection::getOutcomesByCallId($callId, $waitForTasks);
		// Readiness is counted in the distinct types that answered, and in neither of the two
		// things that look like it. Not in result rows: a repeated run stores a second row of the
		// type it ran again, and a row count alone reads that as a type that has never answered.
		// And not in the types that answered with something either: a type stores its row whatever
		// came back {@see \Bitrix\Call\Integration\AI\Task\AITask::buildOutcome()}, a short call
		// really can leave the insights or the evaluation of it with nothing to say, and nobody
		// raises a type again for being empty - so counting content here would keep the call
		// silent for good over a section that was never going to fill. Whether there is anything
		// to show is a question of its own and is asked below, by
		// {@see ChatMessage::generateOverviewMessage()}.
		$typesAnswered = [];
		foreach ($outcomeCollection as $storedOutcome)
		{
			$typesAnswered[(string)$storedOutcome->getType()] = true;
		}
		if (count($typesAnswered) < count($waitForTasks))
		{
			return;
		}

		$call = $outcome->fillCall();
		$chat = Chat::getInstance($call->getChatId());
		if ($chat->getId() != $call->getChatId())
		{
			return;
		}

		// The attempt tells one final message from the next, the transcription result does not: a
		// restart that keeps the transcription produces no new result of that type, and its
		// message would be muted by the claim of the attempt before
		// {@see CallAIService::getCurrentAnalysisAttemptId()}. Asked by the key of the attempt and
		// not by the marker of the message, so that this backstop answers for the same thing the
		// claim below claims; the message carries both {@see ChatMessage::generateOverviewMessage()},
		// and the message of the previous attempt is left in the chat untouched.
		$searches = [
			'attempt' => [
				'CALL_ID' => $callId,
				'MESSAGE_TYPE' => NotifyService::MESSAGE_TYPE_AI_OVERVIEW,
				'ATTEMPT_KEY' => $attemptKey,
			],
		];
		$transcription = $outcomeCollection->getOutcomeByType(SenseType::TRANSCRIBE->value);
		if ($transcription !== null)
		{
			$searches['legacy'] = [
				'CALL_ID' => $callId,
				'MESSAGE_TYPE' => NotifyService::MESSAGE_TYPE_AI_OVERVIEW,
				'OUTCOME_ID' => $transcription->getId(),
			];
		}
		$found = NotifyService::getInstance()->findMessagesByParams($chat->getId(), $searches);
		$existingOverviewMessage = $found['attempt'];
		if ($existingOverviewMessage === null && isset($found['legacy']))
		{
			$existingOverviewMessage = self::isLegacyOverviewOfThisGeneration($found['legacy'], $outcomeCollection)
				? $found['legacy']
				: null
			;
		}
		if ($existingOverviewMessage !== null)
		{
			return;
		}

		$messageOutcome = ChatMessage::generateOverviewMessage(
			$callId,
			$outcomeCollection,
			$chat,
			$attemptId,
			$attemptKey
		);
		if (!$messageOutcome)
		{
			return;
		}

		// Atomic serialization point across concurrent handlers: only the claim winner sends the
		// overview and runs the one-shot side-effects.
		if (!NotifyService::getInstance()->claim($callId, NotifyService::MESSAGE_TYPE_AI_OVERVIEW, $attemptKey))
		{
			return;
		}

		$sendingConfig = (new SendingConfig())->enableSkipUrlIndex();
		$context = (new Context())->setUser($call->getInitiatorId());
		self::logUnsentMessage(
			$callId,
			NotifyService::MESSAGE_TYPE_AI_OVERVIEW,
			NotifyService::getInstance()->sendMessageDeferred($chat, $messageOutcome, $sendingConfig, $context)
		);

		CallAIService::getInstance()->removeExpectation($call->getId());

		$callInstance = Registry::getCallWithId($call->getId());
		(new FollowUpAnalytics($callInstance))
			->addFollowUpResultMessage()
			->sendTelemetry(
				source: null,
				status: 'success',
				event: 'follow_up_result'
			)
		;

		TrackDeletionService::getInstance()->tryDeleteAiTracksFromMixer($call->getId());
	}

	/**
	 * Whether a final message of the shape the code before this feature wrote still reports on
	 * this very attempt.
	 *
	 * That code keyed the message on the result of the transcription and wrote neither value of
	 * the attempt into it {@see ChatMessage::generateOverviewMessage()}, so the search by the key
	 * of the attempt can never see it, and the chat is asked for it by that result as well.
	 * Without this second look the backstop behind the claim would be missing for good on every
	 * call analysed before the release: the claim lives for {@see NotifyService::SEND_GUARD_TTL}
	 * and after that the search of the chat is the only barrier left, so a repeated delivery of
	 * the same result - a retry of the job of the queue, a pass of the agent of the wait - would
	 * send a second final message and run every one-shot effect behind the claim a second time.
	 *
	 * Both looks are one pass over the chat {@see NotifyService::findMessagesByParams()}: each of
	 * them is a selection of the last messages of the chat and a reading of their params, the same
	 * page both times, and this runs on every completion of every call - so the page is read once
	 * and answered for both keys, and this method judges only the message it already has.
	 *
	 * Bounded by what the message can be reporting on, because the key of the previous code
	 * outlives the attempt that wrote it: a restart that keeps the transcription produces no new
	 * result of that type, so the message of the attempt before names the transcription of this
	 * one too, and taken unbounded it would mute every dependent restart of every call analysed
	 * before the release - permanently, which is the very defect the key of the attempt was
	 * introduced to remove. A result stored after the message was written is a result that
	 * message cannot be reporting on, and where the set holds one the message belongs to an
	 * earlier generation and bars nothing.
	 *
	 * A message that answers no date at all bars nothing either: the bound cannot be asked, and
	 * of the two ways to be wrong the recoverable one is a second message, not a call silenced
	 * for good.
	 */
	private static function isLegacyOverviewOfThisGeneration(
		\Bitrix\Im\V2\Message $legacyMessage,
		OutcomeCollection $outcomeCollection
	): bool
	{
		$writtenAt = $legacyMessage->getDateCreate();
		if ($writtenAt === null)
		{
			return false;
		}

		foreach ($outcomeCollection as $storedOutcome)
		{
			$storedAt = $storedOutcome->getDateCreate();
			if ($storedAt !== null && $storedAt->getTimestamp() > $writtenAt->getTimestamp())
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * @see CallAIService::fireCallAiFailedEvent
	 */
	public static function onCallAiTaskFailed(\Bitrix\Main\Event $event): void
	{
		if (!CallAISettings::isCallAIEnable())
		{
			return;
		}

		$error = $event->getParameters()['error'] ?? null;
		$task = $event->getParameters()['task'] ?? null;

		if (
			$task instanceof \Bitrix\Call\Integration\AI\Task\AITask
			&& $error instanceof \Bitrix\Main\Error
			&& $task->allowNotifyTaskFailed()
		)
		{
			$call = Registry::getCallWithId($task->getCallId());
			if ($call)
			{
				CallAIService::getInstance()->removeExpectation($call->getId());

				NotifyService::getInstance()->sendTaskFailedMessage($error, $call);
			}
		}
	}

	/**
	 * A follow-up message of a call that did not reach the chat. The pass that sent it is not
	 * failed for its sake: these messages have no contract of retries, and a pass that died here
	 * would leave everything after it undone as well. The line is their only trace, and it names
	 * the call - such a message belongs to the call as a whole and there is no track to point at.
	 */
	private static function logUnsentMessage(int $callId, string $messageType, \Bitrix\Main\Result $outcome): void
	{
		if ($outcome->isSuccess())
		{
			return;
		}

		Logger::getInstance()->error(
			"EventService: the {$messageType} message of call #{$callId} was not sent to chat."
			. ' Errors: ' . implode('; ', $outcome->getErrorMessages()) . '.'
		);
	}
}

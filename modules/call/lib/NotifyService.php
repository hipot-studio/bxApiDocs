<?php

namespace Bitrix\Call;

use Bitrix\Call\Integration\AI\ChatMessage;
use Bitrix\Call\Analytics\CloudRecordAnalytics;
use Bitrix\Call\Analytics\FollowUpAnalytics;
use Bitrix\Call\Background\JobDispatcher;
use Bitrix\Call\Logger\Logger;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChatFactory;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Params;
use Bitrix\Im\V2\Message\Send\SendingConfig;
use Bitrix\Im\V2\Message\Send\SendResult;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Service\Context;
use Bitrix\Main\Application;
use Bitrix\Main\Result;
use Bitrix\Main\Security\Sign\Signer;


class NotifyService
{
	/** @see \Bitrix\Call\Integration\Chat::onStateChange */
	public const
		MESSAGE_COMPONENT_ID = 'CallMessage'
	;
	public const
		MESSAGE_TYPE_START = 'START',
		MESSAGE_TYPE_FINISH = 'FINISH',
		MESSAGE_TYPE_DECLINED = 'DECLINED',
		MESSAGE_TYPE_BUSY = 'BUSY',
		MESSAGE_TYPE_MISSED = 'MISSED',
		MESSAGE_TYPE_CANCELLED = 'CANCELLED',
		MESSAGE_TYPE_ERROR = 'ERROR',
		MESSAGE_TYPE_AI_OVERVIEW = 'AI_OVERVIEW',
		MESSAGE_TYPE_AI_START = 'AI_START',
		MESSAGE_TYPE_AI_FAILED = 'AI_FAILED',
		MESSAGE_TYPE_AI_INFO = 'AI_INFO',
		MESSAGE_TYPE_AI_WAIT = 'AI_WAIT',
		MESSAGE_TYPE_AI_DESTROY = 'AI_DESTROY',
		MESSAGE_TYPE_AUDIO_RECORD = 'AUDIO_RECORD',
		MESSAGE_TYPE_CLOUD_RECORD_PREPARE = 'CLOUD_RECORD_PREPARE',
		MESSAGE_TYPE_CLOUD_RECORD_READY = 'CLOUD_RECORD_READY',
		MESSAGE_TYPE_NEW_USER_LIMIT = 'NEW_USER_LIMIT'
	;

	public const ADMIN_NOTIFICATION_TAG = 'call_registration';

	/**
	 * The two failures the delivery of a record to chat answers with, and the only ones it owns
	 * {@see self::sendRecordingReadyMessage()}. They carry codes of their own because the
	 * finalization of the track treats them differently and has to tell them from the codes of
	 * everybody else on the same path - IM_MODULE_NOT_LOADED, CALL_NOT_FOUND and the rest
	 * {@see \Bitrix\Call\Track\TrackService::finalizeDownload()}.
	 */
	public const
		DELIVERY_FAILED = 'DELIVERY_FAILED',
		DELIVERY_NOT_CONFIRMED = 'DELIVERY_NOT_CONFIRMED'
	;

	/**
	 * Key of the custom data of those two errors: the id of the record whose delivery failed.
	 *
	 * The caller does not always hold it. One pass of the finalization carries several deliveries
	 * - a preview finishing its download delivers every record of the call
	 * {@see \Bitrix\Call\Track\TrackService::processCloudVideoTrack()} - and without the id the
	 * mark of the failure and the line in the journal would both name the track being finalized,
	 * that is the preview, and not the recording that never reached the chat.
	 */
	public const DELIVERY_TRACK_ID = 'trackId';

	private const
		SEND_GUARD_TTL = 10,
		SEND_GUARD_SALT = 'call.notify.sendguard',
		SEND_COMPLETION_FAILED = 'sendCompletionFailed'
	;

	private static ?NotifyService $service = null;

	private array $shownMessage = [];

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

	public function sendTaskFailedMessage(\Bitrix\Main\Error $error, Call $call, int $checkDuplicateDepth = 3): self
	{
		if ($this->isMessageShown($call->getId(), self::MESSAGE_TYPE_AI_FAILED))
		{
			return $this;
		}
		$this->setMessageShown($call->getId(), self::MESSAGE_TYPE_AI_FAILED);

		$chat = Chat::getInstance($call->getChatId());

		if ($chat->getId() > 0)
		{
			if (
				$checkDuplicateDepth <= 0
				|| $error->isAiGeneratedError()
				|| $this->findMessage($chat->getId(), $call->getId(), self::MESSAGE_TYPE_AI_FAILED, $checkDuplicateDepth) === null
			)
			{
				$message = ChatMessage::generateTaskFailedMessage($call->getId(), $error, $chat);
				if ($message)
				{
					// No cross-process claim on AI_FAILED: the isAiGeneratedError() bypass above
					// intentionally lets each parallel AI task surface its own error.
					$sendingConfig = (new SendingConfig())
						->enableSkipCounterIncrements()
						->enableSkipUrlIndex()
					;
					$context = (new Context())->setUser($call->getInitiatorId());
					$this->logMessageFailure(
						$call,
						self::MESSAGE_TYPE_AI_FAILED,
						$this->sendMessageDeferred($chat, $message, $sendingConfig, $context)
					);

					(new FollowUpAnalytics($call))->addFollowUpErrorMessage($error->getCode() ?? 'UNDEFINED');
				}
			}
			$this->sendAudioRecordMessage($call);
		}

		return $this;
	}

	public function sendCallError(\Bitrix\Main\Error $error, Call $call, int $checkDuplicateDepth = 3): self
	{
		if ($this->isMessageShown($call->getId(), self::MESSAGE_TYPE_AI_FAILED))
		{
			return $this;
		}
		$this->setMessageShown($call->getId(), self::MESSAGE_TYPE_AI_FAILED);

		$chat = Chat::getInstance($call->getChatId());

		if ($chat->getId())
		{
			if (
				$checkDuplicateDepth <= 0
				|| $this->findMessage($chat->getId(), $call->getId(), self::MESSAGE_TYPE_AI_FAILED, $checkDuplicateDepth) === null
			)
			{
				$errorMessage = ChatMessage::generateErrorMessage($error, $chat, $call);
				if ($errorMessage)
				{
					$this->sendError($chat, $errorMessage);

					(new FollowUpAnalytics($call))->addFollowUpErrorMessage($error->getCode() ?? 'UNDEFINED');
				}
			}

			$this->sendAudioRecordMessage($call);
		}

		return $this;
	}

	public function sendTaskWaitMessage(Call $call): self
	{
		if ($this->isMessageShown($call->getId(), self::MESSAGE_TYPE_AI_WAIT))
		{
			return $this;
		}
		$this->setMessageShown($call->getId(), self::MESSAGE_TYPE_AI_WAIT);

		$chat = Chat::getInstance($call->getChatId());

		if (
			$chat->getId()
			&& $this->findMessage($chat->getId(), $call->getId(), self::MESSAGE_TYPE_AI_WAIT, 3) === null
		)
		{
			$message = ChatMessage::generateWaitMessage($call, $chat);
			if ($message)
			{
				// Claim-before-send on the (callId, AI_WAIT) key: the DB check above is not
				// atomic under concurrent wait-agent deliveries.
				if (!$this->claim($call->getId(), self::MESSAGE_TYPE_AI_WAIT))
				{
					return $this;
				}

				$sendingConfig = (new SendingConfig())
					->enableSkipCounterIncrements()
					->enableSkipUrlIndex()
				;
				$context = (new Context())->setUser($call->getInitiatorId());
				$this->sendMessageDeferred($chat, $message, $sendingConfig, $context);
			}
		}

		return $this;
	}

	public function sendOpponentBusyMessage(int $currentUserId, int $opponentUserId): self
	{
		$chat = ChatFactory::getInstance()->getPrivateChat($currentUserId, $opponentUserId);
		if ($chat->getId() > 0)
		{
			$message = CallChatMessage::generateOpponentBusyMessage($opponentUserId);
			if ($message)
			{
				$sendingConfig = (new SendingConfig)
					->disableSkipCounterIncrements()
					->enableSkipUrlIndex()
				;
				$context = (new Context())->setUser($opponentUserId);
				$this->sendMessageDeferred($chat, $message, $sendingConfig, $context);
			}
		}

		return $this;
	}

	/**
	 * The outcome of the send travels to the caller: im reports a failed send by filling the
	 * result rather than by throwing {@see \Bitrix\Im\V2\Chat::sendMessage()}, so a swallowed
	 * return value made an unsuccessful delivery unreachable in principle.
	 */
	public function sendMessage(Chat $chat, Message $message, ?SendingConfig $sendingConfig = null, ?Context $context = null): Result
	{
		$result = $this->deliverToChat($chat, $message, $sendingConfig, $context);

		$this->completeSendingOnCronRun($result);

		return $result;
	}

	/**
	 * Same shape as sendMessage() on purpose: two neighbouring methods with one body and different
	 * return contracts would be a trap for the next reader.
	 */
	public function sendError(Chat $chat, Message $message, ?SendingConfig $sendingConfig = null, ?Context $context = null): Result
	{
		$result = $this->deliverToChat($chat, $message, $sendingConfig, $context);

		$this->completeSendingOnCronRun($result);

		return $result;
	}

	/**
	 * The send itself, and the only place the chat is asked for it. A set that has to answer for
	 * the chat replaces this method in an heir - the same kind of seam as the sign of the context
	 * in the dispatcher {@see \Bitrix\Call\Background\JobDispatcher::isCronRun()} - and that
	 * seam is narrower than a double of the whole service: everything the send is wrapped in, the
	 * finishing of its second half included, stays the production code.
	 */
	protected function deliverToChat(
		Chat $chat,
		Message $message,
		?SendingConfig $sendingConfig,
		?Context $context
	): SendResult
	{
		return $chat
			->setContext($context ?? new Context)
			->sendMessage($message, $sendingConfig)
		;
	}

	public function sendMessageDeferred(Chat $chat, Message $message, ?SendingConfig $sendingConfig = null, ?Context $context = null): Result
	{
		return JobDispatcher::getInstance()->enqueueWithOutcome(
			job: [$this, 'sendMessage'],
			args: [$chat, $message, $sendingConfig, $context],
			priority: Application::JOB_PRIORITY_LOW
		);
	}

	/**
	 * The second half of an im send - chat update, counters, pushes, the after-send event, the
	 * index of the links - is deferred by im itself into the same background queue of the core
	 * {@see \Bitrix\Im\V2\Chat::sendMessage()}, so a process without an epilogue would leave the
	 * message in the history of the chat and send no push. On a cron run it is finished here; on a
	 * hit it is left alone, because the queue runs it after the response and waiting for it would
	 * only make that response longer.
	 *
	 * The verdict of the delivery stays the result of the save and not of this wait: a message
	 * already in the history is delivered, and a repeat would find it there and send nothing, so
	 * the push it missed is not replayed by a failure either. Hence the catch - wait() rethrows
	 * what the queue caught {@see \Bitrix\Im\V2\Async\Queue::wait()}, and without it a saved
	 * message would come back to the dispatcher as a failure.
	 *
	 * The queue of im keeps its isScheduled flag raised after a wait of ours - it is lowered in
	 * runInBackground() alone - so the next promise of this process no longer reaches the queue of
	 * the core. Harmless while we finish every promise ourselves, and written down here because
	 * a change of that detail in im would break this quietly.
	 */
	private function completeSendingOnCronRun(SendResult $result): void
	{
		$promise = $result->getPromise();
		if ($promise === null || !JobDispatcher::getInstance()->isCronRun())
		{
			return;
		}

		try
		{
			$promise->wait();
		}
		catch (\Throwable $e)
		{
			// The verdict stays the result of the save, so this failure travels as data and never
			// as an error: turning it into one would send the finalization of the track into a
			// retry that republishes nothing. The caller that knows the call and the track makes a
			// mark of it {@see self::sendRecordingReadyMessage()}.
			$result->setData($result->getData() + [self::SEND_COMPLETION_FAILED => true]);

			Logger::getInstance()->error("NotifyService: completing a send during a cron run failed: " . $e);
		}
	}

	public function findMessage(int $chatId, int $callId, string $messageType, int $depth = 100): ?Message
	{
		return $this->findMessageByParams($chatId, ['CALL_ID' => $callId, 'MESSAGE_TYPE' => $messageType], $depth);
	}

	/**
	 * @param int $chatId
	 * @param array $messageParams
	 * @param int $depth
	 * @return Message|null
	 */
	public function findMessageByParams(int $chatId, array $messageParams, int $depth = 100): ?Message
	{
		return $this->findMessagesByParams($chatId, [$messageParams], $depth)[0];
	}

	/**
	 * The newest message of the chat for each of several sets of params, read in one pass: one
	 * selection of the messages and one reading of their params, whatever the number of sets.
	 *
	 * Each set is answered exactly as {@see self::findMessageByParams()} answers it alone - the
	 * newest message whose component params hold every value of the set, and no further back than
	 * the start of its call: a set stops looking at the first message of MESSAGE_TYPE_START of its
	 * CALL_ID that it does not match itself. The pass ends when every set is found or stopped.
	 *
	 * @param int $chatId
	 * @param array<array-key, array> $paramSets every set carries CALL_ID, as the single search needs
	 * @param int $depth
	 * @return array<array-key, Message|null> by the key of the set; null where the set found nothing
	 */
	public function findMessagesByParams(int $chatId, array $paramSets, int $depth = 100): array
	{
		$found = array_fill_keys(array_keys($paramSets), null);
		if ($paramSets === [])
		{
			return $found;
		}

		$messages = MessageCollection::find(['CHAT_ID' => $chatId], ['ID' => 'DESC'], $depth);
		if ($messages->count() === 0)
		{
			return $found;
		}

		$messages->fillParams();

		$searching = $paramSets;
		foreach ($messages as $message)
		{
			$params = $message->getParams();
			if (!$params->isSet(Params::COMPONENT_PARAMS))
			{
				continue;
			}

			$componentParams = $params->get(Params::COMPONENT_PARAMS)->getValue();
			if (!isset($componentParams['CALL_ID'], $componentParams['MESSAGE_TYPE']))
			{
				continue;
			}

			foreach ($searching as $setKey => $messageParams)
			{
				if ($componentParams['CALL_ID'] != $messageParams['CALL_ID'])
				{
					continue;
				}

				$allMatched = true;
				foreach ($messageParams as $key => $value)
				{
					if (!isset($componentParams[$key]) || $componentParams[$key] != $value)
					{
						$allMatched = false;
						break;
					}
				}

				if ($allMatched)
				{
					$found[$setKey] = $message;
					unset($searching[$setKey]);
				}
				elseif ($componentParams['MESSAGE_TYPE'] === self::MESSAGE_TYPE_START)
				{
					unset($searching[$setKey]);
				}
			}

			if ($searching === [])
			{
				break;
			}
		}

		return $found;
	}

	public function findMessagesForCall(int $chatId, int $callId, int $depth = 100): MessageCollection
	{
		$result = new MessageCollection();

		$messages = MessageCollection::find(['CHAT_ID' => $chatId], ['ID' => 'DESC'], $depth);
		if ($messages->count() === 0)
		{
			return $result;
		}

		$messages->fillParams();

		foreach ($messages as $message)
		{
			$params = $message->getParams();

			/** @see \Bitrix\Call\Integration\Chat::onStateChange */
			if (
				$params->isSet(Params::COMPONENT_PARAMS)
				&& $params->get(Params::COMPONENT_PARAMS)->getValue()['CALL_ID'] == $callId
			)
			{
				$result->add($message);

				if ($params->get(Params::COMPONENT_PARAMS)->getValue()['MESSAGE_TYPE'] == self::MESSAGE_TYPE_START)
				{
					break;
				}
			}
		}

		return $result;
	}

	/**
	 * The outcome of the delivery of a cloud recording to chat, as precise as the context allows:
	 * on a hit the success of the enqueue, in a console process the outcome of the send itself
	 * {@see \Bitrix\Call\Background\JobDispatcher::enqueueWithOutcome()}.
	 *
	 * An early exit is a success - a track of another type, no im module, no chat, a message
	 * already in the chat. None of them is a failure of the delivery; they are its lawful absence,
	 * and the finalization of the track has nothing to retry for them.
	 *
	 * A lost claim is neither: it answers DELIVERY_NOT_CONFIRMED, because this process knows
	 * nothing about what the winner of the race did. Counting it as a success would let a process
	 * that sent nothing take the last agent of the record off the schedule while the winner failed
	 * on its own send, and the recording would be lost. The price is one extra pass in a rare
	 * race: on the next one the DB check either finds the message of the winner and answers
	 * success at once, or does not find it and this process sends.
	 */
	public function sendRecordingReadyMessage(Call $call, Track $track): Result
	{
		$result = new Result();

		if ($track->getType() !== Track::TYPE_VIDEO_RECORD)
		{
			return $result;
		}

		if (!\Bitrix\Main\Loader::includeModule('im'))
		{
			return $result;
		}

		$chat = Chat::getInstance($call->getChatId());
		if (!$chat || $chat instanceof \Bitrix\Im\V2\Chat\NullChat)
		{
			return $result;
		}

		$existingMessage = $this->findMessageByParams(
			$chat->getId(),
			[
				'CALL_ID' => $call->getId(),
				'MESSAGE_TYPE' => self::MESSAGE_TYPE_CLOUD_RECORD_READY,
				'TRACK_ID' => $track->getId(),
			]
		);
		if ($existingMessage !== null)
		{
			return $result;
		}

		if (!$track->getDiskFileId())
		{
			$diskResult = $track->attachToDisk();
			if (!$diskResult->isSuccess())
			{
				(new CloudRecordAnalytics($call))->addSendMessage($track, 'disk_upload_error');
				$this->logDeliveryFailure($call, $track, 'the file was not attached to disk', $diskResult);

				return $result->addError(new \Bitrix\Main\Error(
					'Recording was not attached to disk',
					self::DELIVERY_FAILED,
					[self::DELIVERY_TRACK_ID => (int)$track->getId()],
				));
			}
		}

		// Cross-process claim-before-send: trackReady callbacks may be delivered more
		// than once, and the DB check above is not atomic under concurrent delivery.
		// Discriminated by TRACK_ID: a call legitimately has one record-ready message
		// per track, so collapsing them would drop legitimate messages. Claimed after
		// attachToDisk() so the short send-guard TTL spans only the enqueue->DB window,
		// not the synchronous disk upload, which can outlast it.
		if (!$this->claim($call->getId(), self::MESSAGE_TYPE_CLOUD_RECORD_READY, (string)$track->getId()))
		{
			return $result->addError(new \Bitrix\Main\Error(
				'Recording delivery is being done by another process',
				self::DELIVERY_NOT_CONFIRMED,
				[self::DELIVERY_TRACK_ID => (int)$track->getId()],
			));
		}

		$message = CallChatMessage::makeCloudRecordReadyMessage($call, $chat, $track);

		$sendingConfig = (new SendingConfig())
			->enableSkipCounterIncrements()
			->enableSkipUrlIndex()
		;
		$context = (new Context())->setUser($call->getInitiatorId());

		$sendResult = $this->sendMessageDeferred($chat, $message, $sendingConfig, $context);

		if (!$sendResult->isSuccess())
		{
			// The claim guarded a send that never happened, so it goes back at once: the retry of
			// the finalization has to be able to win it again, and it arrives long before the
			// short TTL of the claim runs out {@see self::release()}.
			$this->release($call->getId(), self::MESSAGE_TYPE_CLOUD_RECORD_READY, (string)$track->getId());

			$this->logDeliveryFailure($call, $track, 'the message was not sent', $sendResult);

			// Both marks carry the outcome of the send and not the fact that it was attempted: on
			// the inline path they used to go out as success whatever happened, and that is the
			// very counter this defect was meant to be seen by.
			(new CloudRecordAnalytics($call))
				->addSendMessage($track, 'chat_send_error')
				->sendTelemetry(
					source: $track,
					status: 'error',
					errorCode: 'chat_send_error',
					event: 'cloud_record_chat_sent',
				)
			;

			return $result->addError(new \Bitrix\Main\Error(
				'Recording message was not sent',
				self::DELIVERY_FAILED,
				[self::DELIVERY_TRACK_ID => (int)$track->getId()],
			));
		}

		(new CloudRecordAnalytics($call))
			->addSendMessage($track)
			->sendTelemetry(
				source: $track,
				status: 'success',
				event: 'cloud_record_chat_sent',
			)
		;

		// The message is in the history of the chat and the delivery is done, so the verdict stays
		// success and nothing is retried - a repeat would find the message and send nothing, which
		// would not replay the push that was lost anyway. What did not happen still leaves the
		// portal by the same road as a failed delivery, in the row of the delivery error.
		if (($sendResult->getData()[self::SEND_COMPLETION_FAILED] ?? false) === true)
		{
			$this->logDeliveryFailure($call, $track, 'the message was sent but not completed');

			(new CloudRecordAnalytics($call))->sendTelemetry(
				source: $track,
				status: 'error',
				errorCode: 'chat_send_incomplete',
				event: 'cloud_record_chat_send_failed',
			);
		}

		return $result;
	}

	/**
	 * A service message of a call that did not reach the chat. These have no contract of retries -
	 * failing the pass of the agent for their sake would make things worse, not better - so the
	 * line below is their only trace, and it names the call the message belongs to. No track is
	 * named: such a message is about the whole call and there is no track to point at.
	 */
	private function logMessageFailure(Call $call, string $messageType, Result $outcome): void
	{
		if ($outcome->isSuccess())
		{
			return;
		}

		Logger::getInstance()->error(
			"NotifyService: the {$messageType} message of call #{$call->getId()} was not sent to chat."
			. ' Errors: ' . implode('; ', $outcome->getErrorMessages()) . '.'
		);
	}

	/**
	 * The failed delivery of a recording, named by the call and the track it belongs to: a pass of
	 * the agent carries files of several calls, and a line without both ids says nothing about
	 * which recording is missing from which chat.
	 */
	private function logDeliveryFailure(Call $call, Track $track, string $reason, ?Result $outcome = null): void
	{
		$errors = $outcome === null ? '' : ' Errors: ' . implode('; ', $outcome->getErrorMessages()) . '.';

		Logger::getInstance()->error(
			"NotifyService: the recording of call #{$call->getId()}, track #{$track->getId()}"
			. " was not delivered to chat: {$reason}.{$errors}"
		);
	}

	public function sendAudioRecordMessage(Call $call): self
	{
		// Check if audio record message was already sent
		if ($this->isMessageShown($call->getId(), self::MESSAGE_TYPE_AUDIO_RECORD))
		{
			return $this;
		}
		$this->setMessageShown($call->getId(), self::MESSAGE_TYPE_AUDIO_RECORD);

		$chat = Chat::getInstance($call->getChatId());

		if ($chat->getId() > 0)
		{
			// Check if audio record message already exists in chat
			if ($this->findMessage($chat->getId(), $call->getId(), self::MESSAGE_TYPE_AUDIO_RECORD) === null)
			{
				$messages = ChatMessage::generateAudioRecordMessages($call, $chat);
				if (!empty($messages))
				{
					// One claim for the whole batch on the (callId, AUDIO_RECORD) key, taken
					// before the loop: the winner enqueues every track message, a concurrent
					// repeat is suppressed as one unit. No per-track discriminator - the batch
					// is gated together, matching the isMessageShown(AUDIO_RECORD) barrier above.
					if (!$this->claim($call->getId(), self::MESSAGE_TYPE_AUDIO_RECORD))
					{
						return $this;
					}

					$sendingConfig = (new SendingConfig())->enableSkipCounterIncrements();
					$context = (new Context())->setUser($call->getInitiatorId());
					foreach ($messages as $message)
					{
						$this->logMessageFailure(
							$call,
							self::MESSAGE_TYPE_AUDIO_RECORD,
							$this->sendMessageDeferred($chat, $message, $sendingConfig, $context)
						);
					}
				}
			}
		}

		return $this;
	}

	//region Admin Notify
	public function addAdminNotify(string $message): self
	{
		\CAdminNotify::add([
			'MESSAGE' => $message,
			'TAG' => self::ADMIN_NOTIFICATION_TAG,
			'MODULE_ID' => 'call',
			'ENABLE_CLOSE' => 'Y',
			'NOTIFY_TYPE' => \CAdminNotify::TYPE_NORMAL,
		]);

		return $this;
	}

	public function addAdminNotifyError(string $message): self
	{
		\CAdminNotify::add([
			'MESSAGE' => $message,
			'TAG' => self::ADMIN_NOTIFICATION_TAG,
			'MODULE_ID' => 'call',
			'ENABLE_CLOSE' => 'Y',
			'NOTIFY_TYPE' => \CAdminNotify::TYPE_ERROR,
		]);

		return $this;
	}
	public function clearAdminNotify(): self
	{
		\CAdminNotify::DeleteByTag(self::ADMIN_NOTIFICATION_TAG);
		return $this;
	}

	//endregion

	//region Message shown tracking
	public function isMessageShown(int $callId, string $messageType): bool
	{
		return isset($this->shownMessage[$messageType][$callId]);
	}

	public function setMessageShown(int $callId, string $messageType): self
	{
		if (!isset($this->shownMessage[$messageType]))
		{
			$this->shownMessage[$messageType] = [];
		}
		$this->shownMessage[$messageType][$callId] = true;

		return $this;
	}

	//endregion

	//region Idempotent send guard
	/**
	 * Cross-process claim-before-send guard. Builds a deterministic idempotency key
	 * from (callId, messageType, discriminator) and atomically claims it via
	 * {@see Idempotence::addKey()}. Returns true when this caller won the right to
	 * send (the first claim), false when the key was already taken - a concurrent or
	 * repeated send of the same logical message. Mirrors the one-shot broadcast dedup
	 * in {@see Signaling::sendFinishInternal()}.
	 *
	 * The claim is held for a short TTL (self::SEND_GUARD_TTL), not the 24h Idempotence
	 * default: it only has to cover the concurrent-delivery race until the first send lands
	 * in the DB, after which {@see self::findMessage()} backstops. A short TTL lets a send
	 * that never materialised (a failed deferred job) self-heal on a later retry instead of
	 * muting the message for a full day.
	 *
	 * Complements the in-memory {@see self::isMessageShown()} and DB-side
	 * {@see self::findMessage()} barriers with correctness across PHP-FPM workers;
	 * it does not replace them.
	 *
	 * @param int $callId
	 * @param string $messageType one of self::MESSAGE_TYPE_*.
	 * @param string|null $discriminator optional per-message qualifier (e.g. OUTCOME_ID
	 *        or TRACK_ID) that splits several sends of the same type for one call into
	 *        separate claims.
	 * @return bool true if the claim was won, false if it was already held.
	 */
	public function claim(int $callId, string $messageType, ?string $discriminator = null): bool
	{
		$key = $this->buildSendGuardKey($callId, $messageType, $discriminator);

		return Idempotence::addKey($key, self::SEND_GUARD_TTL);
	}

	/**
	 * Releases a claim taken by {@see self::claim()} so a legitimate retry can win it
	 * again. Call it when the send the claim guarded did not go through (e.g. the
	 * enqueue failed), mirroring the clearKey-on-failure release in
	 * {@see Signaling::sendFinish()}.
	 *
	 * @param int $callId
	 * @param string $messageType one of self::MESSAGE_TYPE_*.
	 * @param string|null $discriminator the same qualifier that was passed to claim().
	 * @return void
	 */
	public function release(int $callId, string $messageType, ?string $discriminator = null): void
	{
		Idempotence::clearKey($this->buildSendGuardKey($callId, $messageType, $discriminator));
	}

	/**
	 * Builds the deterministic send-guard idempotency key. The same arguments always
	 * yield the same key; the optional discriminator splits distinct qualifiers into
	 * separate claims. The identifying tuple is signed with the server signing key so the
	 * key cannot be predicted - and thus cannot be pre-claimed - by a client that controls
	 * a UniqueRequestFilter requestId sharing the same flat Idempotence keyspace. Namespaced
	 * under `call:notify:` to stay disjoint from other Idempotence users (e.g. Signaling's
	 * `call_finish_*`).
	 */
	private function buildSendGuardKey(int $callId, string $messageType, ?string $discriminator): string
	{
		$payload = $callId . ':' . $messageType;
		if ($discriminator !== null)
		{
			$payload .= ':' . $discriminator;
		}

		return 'call:notify:' . (new Signer())->getSignature($payload, self::SEND_GUARD_SALT);
	}

	//endregion
}
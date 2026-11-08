<?php

namespace Bitrix\Call;

use Bitrix\Call\Integration\AI\ChatMessage;
use Bitrix\Call\Analytics\CloudRecordAnalytics;
use Bitrix\Call\Analytics\FollowUpAnalytics;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChatFactory;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Params;
use Bitrix\Im\V2\Message\Send\SendingConfig;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Service\Context;
use Bitrix\Main\Application;
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

	private const
		SEND_GUARD_TTL = 10,
		SEND_GUARD_SALT = 'call.notify.sendguard'
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
					$this->sendMessageDeferred($chat, $message, $sendingConfig, $context);

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

	public function sendMessage(Chat $chat, Message $message, ?SendingConfig $sendingConfig = null, ?Context $context = null): self
	{
		$chat
			->setContext($context ?? new Context)
			->sendMessage($message, $sendingConfig)
		;

		return $this;
	}

	public function sendError(Chat $chat, Message $message, ?SendingConfig $sendingConfig = null, ?Context $context = null): self
	{
		$chat
			->setContext($context ?? new Context)
			->sendMessage($message, $sendingConfig)
		;

		return $this;
	}

	public function sendMessageDeferred(Chat $chat, Message $message, ?SendingConfig $sendingConfig = null, ?Context $context = null): self
	{
		Application::getInstance()->addBackgroundJob(
			job: [$this, 'sendMessage'],
			args: [$chat, $message, $sendingConfig, $context],
			priority: Application::JOB_PRIORITY_LOW
		);

		return $this;
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
		$messages = MessageCollection::find(['CHAT_ID' => $chatId], ['ID' => 'DESC'], $depth);
		if ($messages->count() === 0)
		{
			return null;
		}

		$messages->fillParams();

		foreach ($messages as $message)
		{
			$params = $message->getParams();
			if (!$params->isSet(Params::COMPONENT_PARAMS))
			{
				continue;
			}

			$componentParams = $params->get(Params::COMPONENT_PARAMS)->getValue();
			if (
				!isset($componentParams['CALL_ID'], $componentParams['MESSAGE_TYPE'])
				|| $componentParams['CALL_ID'] != $messageParams['CALL_ID']
			)
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
				return $message;
			}

			if ($componentParams['MESSAGE_TYPE'] === self::MESSAGE_TYPE_START)
			{
				break;
			}
		}

		return null;
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

	public function sendRecordingReadyMessage(Call $call, Track $track): void
	{
		if ($track->getType() !== Track::TYPE_VIDEO_RECORD)
		{
			return;
		}

		if (!\Bitrix\Main\Loader::includeModule('im'))
		{
			return;
		}

		$chat = Chat::getInstance($call->getChatId());
		if (!$chat || $chat instanceof \Bitrix\Im\V2\Chat\NullChat)
		{
			return;
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
			return;
		}

		if (!$track->getDiskFileId())
		{
			$diskResult = $track->attachToDisk();
			if (!$diskResult->isSuccess())
			{
				(new CloudRecordAnalytics($call))->addSendMessage($track, 'disk_upload_error');
				return;
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
			return;
		}

		$message = CallChatMessage::makeCloudRecordReadyMessage($call, $chat, $track);

		$sendingConfig = (new SendingConfig())
			->enableSkipCounterIncrements()
			->enableSkipUrlIndex()
		;
		$context = (new Context())->setUser($call->getInitiatorId());

		$this->sendMessageDeferred($chat, $message, $sendingConfig, $context);

		(new CloudRecordAnalytics($call))
			->addSendMessage($track)
			->sendTelemetry(
				source: $track,
				status: 'success',
				event: 'cloud_record_chat_sent',
			)
		;
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
						$this->sendMessageDeferred($chat, $message, $sendingConfig, $context);
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
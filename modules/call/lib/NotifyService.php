<?php

namespace Bitrix\Call;

use Bitrix\Call\Integration\AI\ChatMessage;
use Bitrix\Call\Analytics\FollowUpAnalytics;
use Bitrix\Im\Call\Call;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChatFactory;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Params;
use Bitrix\Im\V2\Message\Send\SendingConfig;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Service\Context;
use Bitrix\Main\Application;


class NotifyService
{
	/** @see \Bitrix\Im\Call\Integration\Chat::onStateChange */
	public const
		MESSAGE_COMPONENT_ID = 'CallMessage'
	;
	public const
		MESSAGE_TYPE_START = 'START',
		MESSAGE_TYPE_FINISH = 'FINISH',
		MESSAGE_TYPE_DECLINED = 'DECLINED',
		MESSAGE_TYPE_BUSY = 'BUSY',
		MESSAGE_TYPE_MISSED = 'MISSED',
		MESSAGE_TYPE_ERROR = 'ERROR',
		MESSAGE_TYPE_AI_OVERVIEW = 'AI_OVERVIEW',
		MESSAGE_TYPE_AI_START = 'AI_START',
		MESSAGE_TYPE_AI_FAILED = 'AI_FAILED',
		MESSAGE_TYPE_AI_INFO = 'AI_INFO',
		MESSAGE_TYPE_AI_WAIT = 'AI_WAIT',
		MESSAGE_TYPE_AI_DESTROY = 'AI_DESTROY',
		MESSAGE_TYPE_AUDIO_RECORD = 'AUDIO_RECORD'
	;

	public const ADMIN_NOTIFICATION_TAG = 'call_registration';

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
				||
				$this->findMessage($chat->getId(), $call->getId(), self::MESSAGE_TYPE_AI_FAILED, $checkDuplicateDepth) === null
			)
			{
				$message = ChatMessage::generateTaskFailedMessage($call->getId(), $error, $chat);
				if ($message)
				{
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
		$messages = MessageCollection::find(['CHAT_ID' => $chatId], ['ID' => 'DESC'], $depth);
		if ($messages->count() === 0)
		{
			return null;
		}

		$messages->fillParams();

		foreach ($messages as $message)
		{
			$params = $message->getParams();

			/** @see \Bitrix\Im\Call\Integration\Chat::onStateChange */
			if (
				$params->isSet(Params::COMPONENT_PARAMS)
				&& isset($params->get(Params::COMPONENT_PARAMS)->getValue()['MESSAGE_TYPE'])
				&& $params->get(Params::COMPONENT_PARAMS)->getValue()['CALL_ID'] == $callId
			)
			{
				if ($params->get(Params::COMPONENT_PARAMS)->getValue()['MESSAGE_TYPE'] == $messageType)
				{
					return $message;
				}
				if ($params->get(Params::COMPONENT_PARAMS)->getValue()['MESSAGE_TYPE'] == self::MESSAGE_TYPE_START)
				{
					break;
				}
			}
		}

		return null;
	}

	/**
	 * @param int $chatId
	 * @param int $callId
	 * @param int $depth
	 * @return MessageCollection<Message>
	 */
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

			/** @see \Bitrix\Im\Call\Integration\Chat::onStateChange */
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

		$downloadUrl = null;
		if ($track->getDiskFileId() && \Bitrix\Main\Loader::includeModule('disk'))
		{
			$diskFile = \Bitrix\Disk\File::getById($track->getDiskFileId());
			if ($diskFile)
			{
				$urlManager = \Bitrix\Disk\Driver::getInstance()->getUrlManager();
				$downloadUrl = $urlManager->getUrlForDownloadFile($diskFile, true);
			}
		}

		if (!$downloadUrl)
		{
			$downloadUrl = $track->getUrl(true, true);
		}

		$message = CallChatMessage::makeCloudRecordReadyMessage($call, $chat, $downloadUrl);

		$sendingConfig = (new SendingConfig())
			->enableSkipCounterIncrements()
			->enableSkipUrlIndex()
		;
		$context = (new Context())->setUser($call->getInitiatorId());

		$this->sendMessageDeferred($chat, $message, $sendingConfig, $context);
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
			if ($this->findMessage($chat->getId(), $call->getId(), self::MESSAGE_TYPE_AUDIO_RECORD, 10) === null)
			{
				$messages = ChatMessage::generateAudioRecordMessages($call, $chat);
				if (!empty($messages))
				{
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
}
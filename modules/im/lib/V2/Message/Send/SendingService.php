<?php

namespace Bitrix\Im\V2\Message\Send;

use Bitrix\Im\V2\Bot\BotService;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Message\Param\ParamError;
use Bitrix\Im\V2\Message\Send\Event\MessageEventLegacy;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Main\Localization\Loc;
use Bitrix\Im\Message\Uuid;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChatError;
use Bitrix\Im\V2\Message\MessageError;
use Bitrix\Im\V2\Common\ContextCustomer;
use CIMMessageParamAttach;

class SendingService
{
	use ContextCustomer;

	private SendingConfig $sendingConfig;

	protected ?Uuid $uuidService;

	public const
		EVENT_BEFORE_MESSAGE_ADD = 'OnBeforeMessageAdd',//new
		EVENT_AFTER_MESSAGE_ADD = 'OnAfterMessagesAdd',
		EVENT_BEFORE_CHAT_MESSAGE_ADD = 'OnBeforeChatMessageAdd',
		EVENT_BEFORE_NOTIFY_ADD = 'OnBeforeMessageNotifyAdd',
		EVENT_AFTER_NOTIFY_ADD = 'OnAfterMessageNotifyAdd'
	;

	/**
	 * @param SendingConfig|null $sendingConfig
	 */
	public function __construct(?SendingConfig $sendingConfig = null)
	{
		if ($sendingConfig === null)
		{
			$sendingConfig = new SendingConfig();
		}
		$this->sendingConfig = $sendingConfig;
	}

	public function getConfig(): SendingConfig
	{
		return $this->sendingConfig;
	}

	//region UUID

	/**
	 * @param Message $message
	 * @return Result
	 */
	public function checkDuplicateByUuid(Message $message): Result
	{
		$result = new Result;

		if (!$this->needToCheckDuplicate($message))
		{
			return $result;
		}

		$this->uuidService = new Uuid($message->getUuid());
		$alreadyExists = !$this->uuidService->add();
		if (!$alreadyExists)
		{
			return $result;
		}

		$messageIdByUuid = $this->uuidService->getMessageId();
		// if we got message_id, then message already exists, and we don't need to add it, so return with ID.
		if (!is_null($messageIdByUuid))
		{
			return $result->setResult(['messageId' => $messageIdByUuid]);
		}

		// if there is no message_id and entry date is expired,
		// then update date_create and return false to delay next sending on the client.
		if (!$this->uuidService->updateIfExpired())
		{
			return $result->addError(new MessageError(MessageError::MESSAGE_DUPLICATED_BY_UUID));
		}

		return $result;
	}

	protected function needToCheckDuplicate(Message $message): bool
	{
		return $message->getUuid() && !$message->isSystem() && Uuid::validate($message->getUuid());
	}

	/**
	 * @param Message $message
	 * @return void
	 */
	public function updateMessageUuid(Message $message): void
	{
		if (isset($this->uuidService))
		{
			$this->uuidService->updateMessageId($message->getMessageId());
		}
	}

	//endregion

	//region Events

	/**
	 * Fires event `im:OnBeforeChatMessageAdd` on before message send.
	 *
	 * @event im:OnBeforeChatMessageAdd
	 * @param Chat $chat
	 * @param Message $message
	 * @return Result
	 */
	public function fireEventBeforeMessageSend(Chat $chat, Message $message): Result
	{
		$result = new Result;

		$messageEvent = new MessageEventLegacy($message);
		$compatibleFields = $messageEvent->getMessageFields();
		$compatibleChatFields = $messageEvent->getChatFields();

		foreach (\GetModuleEvents('im', self::EVENT_BEFORE_CHAT_MESSAGE_ADD, true) as $event)
		{
			$eventResult = \ExecuteModuleEventEx($event, [$compatibleFields, $compatibleChatFields]);
			if ($eventResult === false || (isset($eventResult['result']) && $eventResult['result'] === false))
			{
				$reason = $this->detectReasonSendError($chat->getType(), $eventResult['reason'] ?? '');
				return $result->addError(new ChatError(ChatError::FROM_OTHER_MODULE, $reason));
			}

			if (isset($eventResult['fields']) && is_array($eventResult['fields']))
			{
				unset(
					$eventResult['fields']['MESSAGE_ID'],
					$eventResult['fields']['CHAT_ID'],
					$eventResult['fields']['AUTHOR_ID'],
					$eventResult['fields']['FROM_USER_ID']
				);
				$message->fill($eventResult['fields']);
				$this->sendingConfig->fill($eventResult['fields']);
			}
		}

		return $result;
	}

	/**
	 * Fires event `im:OnAfterMessagesAdd` on before message send.
	 *
	 * @event im:OnAfterMessagesAdd
	 * @param Chat $chat
	 * @param Message $message
	 * @return Result
	 */
	public function fireEventAfterMessageSend(Chat $chat, Message $message): Result
	{
		$result = new Result;

		$messageEvent =
			(new MessageEventLegacy($message))
			->withBot()
			->withFiles()
		;
		$compatibleFields = array_merge(
			$messageEvent->getFields(),
			$this->sendingConfig->toArray(),
		);

		foreach (\GetModuleEvents('im', static::EVENT_AFTER_MESSAGE_ADD, true) as $event)
		{
			\ExecuteModuleEventEx($event, [$message->getMessageId(), $compatibleFields]);
		}

		$botService = (new BotService($this->sendingConfig))->setContext($this->context);
		$botService->runMessageCommand($message->getId(), $compatibleFields);

		return $result;
	}

	public function fireEventBeforeSend(Chat $chat, Message $message): Result
	{
		if (!$this->sendingConfig->isSkipFireEventBeforeMessageNotifySend())
		{
			$result = $this->fireEventBeforeMessageNotifySend($chat, $message);

			if (!$result->isSuccess())
			{
				return $result;
			}
		}

		if (!$chat instanceof Chat\PrivateChat)
		{
			return $this->fireEventBeforeMessageSend($chat, $message);
		}

		return new Result();
	}

	/**
	 * Fires event `im:OnBeforeMessageNotifyAdd` on before message send.
	 *
	 * @event im:OnBeforeMessageNotifyAdd
	 * @param Chat $chat
	 * @param Message $message
	 * @return Result
	 */
	public function fireEventBeforeMessageNotifySend(Chat $chat, Message $message): Result
	{
		$result = new Result;

		$messageEvent = new MessageEventLegacy($message);
		$compatibleFields = array_merge(
			$messageEvent->getMessageFields(),
			$this->sendingConfig->toArray(),
		);
		$compatibleFieldsCopy = $compatibleFields;

		foreach (\GetModuleEvents('im', self::EVENT_BEFORE_NOTIFY_ADD, true) as $arEvent)
		{
			$eventResult = \ExecuteModuleEventEx($arEvent, [&$compatibleFields]);
			if ($eventResult === false || (isset($eventResult['result']) && $eventResult['result'] === false))
			{
				$reason = $this->detectReasonSendError($chat->getType(), $eventResult['reason'] ?? '');
				return $result->addError(new ChatError(ChatError::FROM_OTHER_MODULE, $reason));
			}
			if ($compatibleFields !== $compatibleFieldsCopy)
			{
				$message->fill($compatibleFields);
				$this->sendingConfig->fillByLegacy($compatibleFields);
			}
		}

		return $result;
	}

	/**
	 * Fires event `im:OnAfterNotifyAdd` on before message send.
	 *
	 * @event im:OnAfterNotifyAdd
	 * @param Chat $chat
	 * @param Message $message
	 * @return Result
	 */
	public function fireEventAfterNotifySend(Chat $chat, Message $message): Result
	{
		$result = new Result;

		$compatibleFields = array_merge(
			$message->toArray(),
			$chat->toArray(),
			$this->sendingConfig->toArray(),
		);

		foreach(\GetModuleEvents('im', self::EVENT_AFTER_NOTIFY_ADD, true) as $event)
		{
			\ExecuteModuleEventEx($event, [(int)$message->getMessageId(), $compatibleFields]);
		}

		return $result;
	}

	private function detectReasonSendError($type, $reason = ''): string
	{
		if (!empty($reason))
		{
			$sanitizer = new \CBXSanitizer;
			$sanitizer->addTags([
				'a' => ['href','style', 'target'],
				'b' => [],
				'u' => [],
				'i' => [],
				'br' => [],
				'span' => ['style'],
			]);
			$reason = $sanitizer->sanitizeHtml($reason);
		}
		else
		{
			if ($type == Chat::IM_TYPE_PRIVATE)
			{
				$reason = Loc::getMessage('IM_ERROR_MESSAGE_CANCELED');
			}
			else if ($type == Chat::IM_TYPE_SYSTEM)
			{
				$reason = Loc::getMessage('IM_ERROR_NOTIFY_CANCELED');
			}
			else
			{
				$reason = Loc::getMessage('IM_ERROR_GROUP_CANCELED');
			}
		}

		return $reason;
	}
	//endregion
}

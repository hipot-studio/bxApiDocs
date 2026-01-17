<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Send\Event;

use Bitrix\Im\V2\Chat\PrivateChat;
use Bitrix\Im\V2\Message;

class MessageEventLegacy
{
	private Message $message;
	private bool $withBot = false;
	private bool $withFiles = false;

	public function __construct(Message $message)
	{
		$this->message = $message;
	}

	public function withBot(): self
	{
		$this->withBot = true;

		return $this;
	}

	public function withFiles(): self
	{
		$this->withFiles = true;

		return $this;
	}

	public function getFields(): array
	{
		return array_merge($this->getMessageFields(), $this->getChatFields());
	}

	public function getMessageFields(): array
	{
		$message = $this->message;
		$result = [
			'MESSAGE' => $message->getMessage(),
			'TEMPLATE_ID' => $message->getUuid(),
			'MESSAGE_TYPE' => $message->getChat()->getType(),
			'FROM_USER_ID' => $message->getAuthorId(),
			'DIALOG_ID' => $message->getChat()->getDialogId(),
			'TO_CHAT_ID' => $message->getChatId(),
			'MESSAGE_OUT' => $message->getMessageOut() ?? '',
			'PARAMS' => $message->getEnrichedParams()->toArray(),
			'EXTRA_PARAMS' => $message->getPushParams() ?? [],
			'NOTIFY_MODULE' => $message->getNotifyModule(),
			'NOTIFY_EVENT' => $message->getNotifyEvent(),
			'URL_ATTACH' => $message->getUrl()?->getUrlAttach()?->GetArray() ?? [],
			'AUTHOR_ID' => $message->getAuthorId(),
			'SYSTEM' => $message->isSystem() ? 'Y' : 'N',
		];

		$result['FILES'] = [];

		if (!$this->withFiles)
		{
			$result['FILES'] = $message->getFiles()->getIds();
		}
		else
		{
			foreach ($message->getFiles() as $file)
			{
				$result['FILES'][$file->getId()] = $file->toRestFormat();
			}
		}

		if ($message->getChat() instanceof PrivateChat)
		{
			$result['TO_USER_ID'] = $message->getChat()->getDialogId();
		}

		return $result;
	}

	public function getChatFields(): array
	{
		$message = $this->message;
		$chat = $message->getChat();
		if ($chat instanceof PrivateChat)
		{
			return [];
		}
		$authorRelation = $chat->getRelationByUserId($message->getAuthorId());

		$result = [
			'CHAT_ID' => $chat->getId(),
			'CHAT_PARENT_ID' => $chat->getParentChatId() ?? 0,
			'CHAT_PARENT_MID' => $chat->getParentMessageId() ?? 0,
			'CHAT_TITLE' => $chat->getTitle() ?? '',
			'CHAT_AUTHOR_ID' => $chat->getAuthorId(),
			'CHAT_TYPE' => $chat->getType(),
			'CHAT_AVATAR' => $chat->getAvatarId(),
			'CHAT_COLOR' => $chat->getColor(),
			'CHAT_ENTITY_TYPE' => $chat->getEntityType(),
			'CHAT_ENTITY_ID' => $chat->getEntityId(),
			'CHAT_ENTITY_DATA_1' => $chat->getEntityData1(),
			'CHAT_ENTITY_DATA_2' => $chat->getEntityData2(),
			'CHAT_ENTITY_DATA_3' => $chat->getEntityData3(),
			'CHAT_EXTRANET' => ($chat->getExtranet() ?? false) ? 'Y' : 'N',
			'CHAT_PREV_MESSAGE_ID' => $chat->getPrevMessageId() ?? 0,
			'CHAT_CAN_POST' => $chat->getManageMessages(),
			'RID' => $authorRelation?->getUserId() ?? 1,
			'IS_MANAGER' => ($authorRelation?->getManager() ?? false) ? 'Y' : 'N',
		];

		if ($this->withBot)
		{
			$result['BOT_IN_CHAT'] = $chat->getBotInChat();
		}

		return $result;
	}
}

<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Pull;

use Bitrix\Im\Recent;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Pull\Event\BaseChatEvent;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Pull\EventType;
use Bitrix\Im\V2\Entity\User\User;

class ReadMessages extends BaseChatEvent
{
	public function __construct(
		protected Chat $chat,
		protected MessageCollection $viewedMessages,
		protected int $userId,
		protected int $lastId,
		protected int $counter,
	)
	{
		parent::__construct($chat);
	}

	protected function getBasePullParamsInternal(): array
	{
		$dialogId = $this->chat->getDialogId($this->userId);

		return [
			'dialogId' => $this->chat->getDialogId(),
			'chatId' => $this->chat->getId(),
			'parentChatId' => $this->chat->getParentChatId(),
			'type' => $this->chat->getType(),
			'lastId' => $this->lastId,
			'counter' => $this->counter,
			'muted' => $this->chat->getRelationByUserId($this->userId)?->getNotifyBlock() ?? false,
			'unread' => $dialogId !== null && Recent::isUnread($this->userId, $this->chat->getType(), $dialogId),
			'lines' => $this->chat->getType() === Chat::IM_TYPE_OPEN_LINE,
			'viewedMessages' => $this->viewedMessages->getIds(),
			'recentConfig' => $this->chat->getRecentConfig()->toPullFormat(),
		];
	}

	protected function getType(): EventType
	{
		return EventType::ReadMessages;
	}

	public function shouldSendToOnlySpecificRecipients(): bool
	{
		return true;
	}

	protected function getRecipients(): array
	{
		return [$this->userId];
	}

	protected function getSkippedUserIds(): array
	{
		if ($this->chat->getType() !== Chat::ENTITY_TYPE_LIVECHAT && User::getInstance($this->userId)->isConnector())
		{
			return [$this->userId];
		}

		return [];
	}
}

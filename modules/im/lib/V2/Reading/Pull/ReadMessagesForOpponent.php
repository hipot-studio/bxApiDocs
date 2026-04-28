<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Pull;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Pull\Dto\Diff;
use Bitrix\Im\V2\Pull\Event\BaseChatEvent;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Pull\EventType;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Main\Type\DateTime;

class ReadMessagesForOpponent extends BaseChatEvent
{
	public function __construct(
		protected Chat $chat,
		protected MessageCollection $viewedMessages,
		protected int $userId,
		protected int $lastId,
	)
	{
		parent::__construct($chat);
	}

	protected function getBasePullParamsInternal(): array
	{
		return [
			'dialogId' => $this->chat->getDialogId(),
			'chatId' => $this->chat->getId(),
			'userId' => $this->userId,
			'userName' => User::getInstance($this->userId)->getName(),
			'lastId' => $this->lastId,
			'date' => (new DateTime())->format('c'),
			'viewedMessages' => $this->viewedMessages->getIds(),
			'chatMessageStatus' => $this->chat->getChatMessageStatus(),
		];
	}

	protected function getDiffByUser(int $userId): Diff
	{
		return new Diff($userId, [
			'dialogId' => $this->chat->getDialogId($userId),
		]);
	}

	protected function getType(): EventType
	{
		return EventType::ReadMessagesForOpponent;
	}

	protected function getRecipients(): array
	{
		if ($this->chat->getType() === Chat::IM_TYPE_COMMENT)
		{
			return [];
		}

		return $this->chat->getRelations()->filterActive()->getUserIds();
	}

	protected function getSkippedUserIds(): array
	{
		return [$this->userId];
	}

	public function shouldSendToOnlySpecificRecipients(): bool
	{
		return false;
	}

	public function shouldSendSharedPull(): bool
	{
		return $this->lastId === $this->chat->getLastMessageId();
	}
}

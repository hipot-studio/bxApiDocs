<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Pull\Event;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Pull\Dto\Diff;
use Bitrix\Im\V2\Pull\EventType;

class ChatHistoryDeletedForSelf extends BaseChatEvent
{
	protected int $userId;
	protected int $lastId;
	protected ?int $counter;

	/**
	 * @param int|null $counter the chat counter the user is left with once their unread rows behind
	 * the border are gone. Null when the hit did not count it, and the payload then carries no counter.
	 */
	public function __construct(Chat $chat, int $userId, int $lastId, ?int $counter = null)
	{
		$this->userId = $userId;
		$this->lastId = $lastId;
		$this->counter = $counter;
		parent::__construct($chat);
	}

	protected function getBasePullParamsInternal(): array
	{
		return [
			'chatId' => $this->chat->getId(),
			'dialogId' => $this->chat->getDialogId($this->userId),
			'lastId' => $this->lastId,
		];
	}

	/**
	 * The counter travels with the border for the same reason it does in {@see ChatHistoryDeletedForAll}:
	 * the cleanup lowers it without an event of its own, and the other clients of the user - the one
	 * that asked for the cleanup already knows - would otherwise keep the value that counted the
	 * hidden history in, as long as a message above the border keeps the chat from being emptied.
	 */
	protected function getDiffByUser(int $userId): Diff
	{
		if ($this->counter === null)
		{
			return new Diff($userId);
		}

		return new Diff($userId, ['counter' => $this->counter]);
	}

	public function shouldSendToOnlySpecificRecipients(): bool
	{
		return true;
	}

	protected function getRecipients(): array
	{
		return [$this->userId];
	}

	protected function getType(): EventType
	{
		return EventType::ChatHistoryDeletedForSelf;
	}
}

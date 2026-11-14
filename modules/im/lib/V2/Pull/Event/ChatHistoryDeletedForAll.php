<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Pull\Event;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Pull\Dto\Diff;
use Bitrix\Im\V2\Pull\EventType;
use Bitrix\Im\V2\Reading\Counter\Entity\UsersCounterMap;

class ChatHistoryDeletedForAll extends BaseChatEvent
{
	protected int $lastId;
	protected ?array $recipients;
	protected ?UsersCounterMap $counters;

	/**
	 * The recipients are one batch of the walk that hides the history, never the whole chat: the walk
	 * moves a bounded number of members per pass, and a member learns about the border when their own
	 * batch is done. Without a list the event takes the public paths alone - the tag of the chat, the
	 * shared channel of an open channel - which are the only way to reach a reader with no relation.
	 *
	 * The counters belong to that batch and to nobody else: they are the chat counter each recipient is
	 * left with once their unread rows behind the border are gone, and a reader reached by the public
	 * paths has neither a relation nor a counter to carry.
	 *
	 * @param int[]|null $recipients
	 */
	public function __construct(
		Chat $chat,
		int $lastId,
		?array $recipients = null,
		?UsersCounterMap $counters = null,
	)
	{
		$this->lastId = $lastId;
		$this->recipients = $recipients;
		$this->counters = $counters;
		parent::__construct($chat);
	}

	/**
	 * The event also goes out by the public tag of the chat, and for an open channel to the whole portal,
	 * where there is no user to build a diff for. The handler reads dialogId out of the payload, so the
	 * base parameters carry the dialogId of the chat itself; a private chat still gets the per-user one
	 * from the diff below.
	 */
	protected function getBasePullParamsInternal(): array
	{
		return [
			'chatId' => $this->chat->getId(),
			'dialogId' => $this->chat->getDialogId(),
			'lastId' => $this->lastId,
		];
	}

	/**
	 * The counter travels with the border because the cleanup lowers it without an event of its own:
	 * the unread rows behind the border are deleted, the messages that arrived above it stay unread, and
	 * a client that only hears about the border keeps the value it had until some later event.
	 */
	protected function getDiffByUser(int $userId): Diff
	{
		$params = ['dialogId' => $this->chat->getDialogId($userId)];

		if ($this->counters !== null)
		{
			$params['counter'] = $this->counters->getByUserId($userId);
		}

		return new Diff($userId, $params);
	}

	protected function getType(): EventType
	{
		return EventType::ChatHistoryDeletedForAll;
	}

	public function shouldSendToOnlySpecificRecipients(): bool
	{
		return $this->recipients !== null;
	}

	protected function getRecipients(): array
	{
		return $this->recipients ?? [];
	}
}

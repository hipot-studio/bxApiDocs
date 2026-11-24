<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Comment;

use Bitrix\Im\V2\Rest\RestConvertible;
use Bitrix\Main\Type\DateTime;

/**
 * DTO-04: one cell of the thread list of a chat.
 *
 * The cell is signed by replyCount, the actual aggregate of replies (DTO-01), and messageCount rides
 * along for compatibility and diagnostics only: it counts the system greeting and never decreases on
 * a deletion, so a thread whose replies were all removed would keep looking non-empty by it.
 */
final class ThreadListItem implements RestConvertible
{
	public function __construct(
		public readonly int $chatId,
		public readonly int $parentMessageId,
		public readonly int $messageCount,
		public readonly int $replyCount,
		public readonly int $unreadCount,
		public readonly DateTime $lastMessageDate,
		public readonly array $lastUserIds,
		public readonly bool $isUserSubscribed,
	) {}

	public static function getRestEntityName(): string
	{
		return 'thread';
	}

	public function toRestFormat(array $option = []): array
	{
		return [
			'chatId' => $this->chatId,
			'dialogId' => 'chat' . $this->chatId,
			'parentMessageId' => $this->parentMessageId,
			'messageCount' => $this->messageCount,
			'replyCount' => $this->replyCount,
			'unreadCount' => $this->unreadCount,
			'lastMessageDate' => $this->lastMessageDate->format('c'),
			'lastUserIds' => $this->lastUserIds,
			'isUserSubscribed' => $this->isUserSubscribed,
		];
	}
}

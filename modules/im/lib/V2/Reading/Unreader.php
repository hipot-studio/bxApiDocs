<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading;

use Bitrix\Im\Model\MessageTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Recent\RecentProvider;
use Bitrix\Im\V2\Relation;
use Bitrix\Im\V2\Sync;

class Unreader
{
	public function __construct(
		protected readonly Counter\CountersUpdater $counterUpdater,
		protected readonly Counter\CountersProvider $countersProvider,
		protected readonly RecentProvider $recentProvider,
	) {}

	public function unreadTo(Message $message, int $userId): UnreadResult
	{
		$chat = $message->getChat()->withContextUser($userId);
		$relation = $chat->getSelfRelation();
		if ($relation === null)
		{
			return UnreadResult::error(new ReadingError(ReadingError::USER_NOT_IN_CHAT));
		}

		// the author is excluded from the insert, so a range of own messages would produce a zero
		// counter with a rolled back read position: refuse before any mutation instead
		if (!$this->hasForeignMessagesFrom($message->getMessageId(), $relation))
		{
			return UnreadResult::error(new ReadingError(ReadingError::NOTHING_TO_UNREAD));
		}

		// the insert is idempotent and keeps rows of a previous mark, so drop them first; the read
		// position moves last, otherwise a concurrent read observes an intermediate state
		$this->counterUpdater->delete()->toMessage($message, $userId)->execute();
		$this->counterUpdater->addStartingFrom($message->getMessageId(), $relation);
		Sync\Logger::getInstance()->add(
			new Sync\Event(Sync\Event::ADD_EVENT, Sync\Event::CHAT_ENTITY, $message->getChatId()),
			$userId,
			$chat
		);
		$relation->setLastId($this->resolveRolledBackLastId($message, $chat, $userId))->save();
		$counter = $this->countersProvider->getForUser($message->getChatId(), $userId);
		// a chat with a recent row takes the unread flag, the read-later mark and the activity date
		// from it; for a comment chat the provider returns null and the event falls back to the chat
		$recentItem = $this->recentProvider->getItem($userId, $message->getChatId());
		(new Pull\UnreadChat($chat, $userId, $counter, $recentItem))->send();

		return new UnreadResult($counter);
	}

	/**
	 * The read position becomes the message right before the marked one, but never goes below the
	 * message preceding the user's own history start: the id is global, so for the first message of
	 * a chat the arithmetic would point at a message of another chat. That lower bound is the value
	 * a fresh relation with a hidden history gets ({@see Relation::fillRestriction}), so it reads as
	 * "nothing is read yet" instead of marking the first available message read while it is unread.
	 */
	private function resolveRolledBackLastId(Message $message, Chat $chat, int $userId): int
	{
		return max($message->getMessageId() - 1, $chat->getStartId($userId) - 1, 0);
	}

	private function hasForeignMessagesFrom(int $messageId, Relation $relation): bool
	{
		if ($relation->getMessageType() === \IM_MESSAGE_SYSTEM)
		{
			return true;
		}

		$row = MessageTable::query()
			->setSelect(['ID'])
			->where('CHAT_ID', $relation->getChatId())
			->where('ID', '>=', $messageId)
			->whereNot('AUTHOR_ID', $relation->getUserId())
			->setLimit(1)
			->fetch()
		;

		return $row !== false;
	}
}

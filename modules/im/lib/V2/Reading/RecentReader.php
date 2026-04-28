<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Reading\Counter\CountersProvider;
use Bitrix\Im\V2\Reading\Counter\Internal\CountersCache;
use Bitrix\Im\V2\Reading\Pull\UnreadChat;
use Bitrix\Im\V2\Recent\RecentProvider;
use Bitrix\Im\V2\Recent\RecentUpdater;
use Bitrix\Im\V2\Sync;

class RecentReader
{
	public function __construct(
		private readonly RecentProvider $provider,
		private readonly RecentUpdater $updater,
		private readonly CountersCache $countersCache,
		private readonly CountersProvider $countersProvider,
	) {}

	public function read(int $userId, int $chatId): ReadResult
	{
		return $this->changeReadStatus($userId, $chatId, false);
	}

	public function unread(int $userId, int $chatId, int $markedId): ReadResult
	{
		return $this->changeReadStatus($userId, $chatId, true, $markedId);
	}

	public function readAll(int $userId): void
	{
		$this->updater->update($userId, unread: false);
	}

	public function readByType(int $userId, Chat\Type $type): void
	{
		$this->updater->updateByType($userId, $type, unread: false);
	}

	public function updateDateUpdate(int $userId, int $chatId): void
	{
		$this->updater->update($userId, $chatId);
	}

	private function changeReadStatus(int $userId, int $chatId, bool $unread, int $markedId = 0): ReadResult
	{
		$item = $this->provider->getItem($userId, $chatId);
		if ($item === null)
		{
			return ReadResult::error(new ReadingError(ReadingError::RECENT_ITEM_NOT_FOUND));
		}

		if ($item->isUnread() === $unread && $item->getMarkedId() === $markedId)
		{
			return new ReadResult($this->countersProvider->getForUser($chatId, $userId), new MessageCollection());
		}

		$item->setUnread($unread)->setMarkedId($markedId); // update static cache

		$this->updater->update($userId, $chatId, $unread, $markedId);
		// Recent read doesn't affect message counter, so we can get it before cache invalidation
		$counter = $this->countersProvider->getForUser($chatId, $userId);
		$this->countersCache->remove($userId);

		$chat = Chat::getInstance($chatId);

		(new UnreadChat($chat, $userId, $counter, $item))->send();
		Sync\Logger::getInstance()->add(
			new Sync\Event(Sync\Event::ADD_EVENT, Sync\Event::CHAT_ENTITY, $chatId),
			$userId,
			$chat
		);

		return new ReadResult($counter, new MessageCollection());
	}
}

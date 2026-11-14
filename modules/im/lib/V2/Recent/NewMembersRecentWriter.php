<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Recent;

use Bitrix\Im\Model\RecentTable;
use Bitrix\Im\Recent;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Reading\Counter\CountersProvider;
use Bitrix\Im\V2\Reading\Counter\Internal\CountersCache;
use Bitrix\Im\V2\Reading\Pull\UnreadChat;
use Bitrix\Im\V2\Recent\Internal\RecentItemCache;
use Bitrix\Im\V2\Relation;
use Bitrix\Im\V2\RelationCollection;
use Bitrix\Main\Type\DateTime;

/**
 * @internal
 */
final class NewMembersRecentWriter
{
	private const USER_IDS_CHUNK_SIZE = 500;

	public function __construct(
		private readonly RecentItemCache $recentItemCache,
		private readonly CountersCache $countersCache,
		private readonly CountersProvider $countersProvider,
	)
	{}

	/**
	 * @param RelationCollection $relations members are skipped in the chat and in each ancestor whose last message is before their history start
	 */
	public function write(Chat $chat, RelationCollection $relations, ?int $unreadMarkedMessageId = null): void
	{
		$relations = $this->filterSeeingLastMessage($chat, $relations);
		$userIds = array_values($relations->getUserIds());
		if (empty($userIds))
		{
			return;
		}

		$lastActivity = new DateTime();
		Recent::raiseChat($chat, $relations, $lastActivity, Recent::MAX_RAISE_DEPTH);
		$this->raiseAncestors($chat, $userIds, $lastActivity);

		if ($unreadMarkedMessageId !== null)
		{
			$this->markUnread($chat, $userIds, $unreadMarkedMessageId, $lastActivity);
		}
	}

	/**
	 * @param RelationCollection $relations members who already have a recent item of the chat are skipped
	 */
	public function writeMissing(Chat $chat, RelationCollection $relations, ?int $unreadMarkedMessageId = null): void
	{
		$userIds = array_values($relations->getUserIds());
		if (empty($userIds))
		{
			return;
		}

		$userIdsWithItem = $this->getUserIdsWithRecentItem((int)$chat->getChatId(), $userIds);

		$this->write($chat, $relations->filterExcludingUserIds($userIdsWithItem), $unreadMarkedMessageId);
	}

	/**
	 * @param int[] $userIds
	 */
	private function raiseAncestors(Chat $chat, array $userIds, DateTime $lastActivity): void
	{
		for ($depth = 1; $depth <= Recent::MAX_RAISE_DEPTH && $userIds !== [] && $chat->hasParent(); $depth++)
		{
			$chat = $chat->getParentChat();
			$relations = $chat->getRelationsByUserIds($userIds);
			Recent::raiseChat($chat, $this->filterSeeingLastMessage($chat, $relations), $lastActivity, Recent::MAX_RAISE_DEPTH);
			$userIds = array_values($relations->getUserIds());
		}
	}

	private function filterSeeingLastMessage(Chat $chat, RelationCollection $relations): RelationCollection
	{
		$lastMessageId = (int)$chat->getLastMessageId();

		return $relations->filter(static fn(Relation $relation): bool => (int)$relation->getStartId() <= $lastMessageId);
	}

	/**
	 * @param int[] $userIds
	 * @return int[]
	 */
	private function getUserIdsWithRecentItem(int $chatId, array $userIds): array
	{
		$result = [];
		foreach (array_chunk($userIds, self::USER_IDS_CHUNK_SIZE) as $userIdsChunk)
		{
			$rows = RecentTable::query()
				->setSelect(['USER_ID'])
				->where('ITEM_CID', $chatId)
				->whereIn('USER_ID', $userIdsChunk)
				->fetchAll()
			;
			foreach ($rows as $row)
			{
				$result[] = (int)$row['USER_ID'];
			}
		}

		return $result;
	}

	/**
	 * @param int[] $userIds
	 */
	private function markUnread(Chat $chat, array $userIds, int $markedId, DateTime $lastActivity): void
	{
		$chatId = (int)$chat->getChatId();

		foreach (array_chunk($userIds, self::USER_IDS_CHUNK_SIZE) as $userIdsChunk)
		{
			RecentTable::updateByFilter(
				['=ITEM_CID' => $chatId, '=USER_ID' => $userIdsChunk],
				['UNREAD' => 'Y', 'MARKED_ID' => $markedId, 'DATE_UPDATE' => $lastActivity],
			);
		}

		$counters = $this->countersProvider->getForUsers($chatId, $userIds);

		foreach ($userIds as $userId)
		{
			$this->recentItemCache->remove($userId, $chatId);
			$this->countersCache->remove($userId);

			$item = (new RecentItem())
				->setChatId($chatId)
				->setDialogId($chat->getDialogId())
				->setMessageId((int)$chat->getLastMessageId())
				->setUnread(true)
				->setMarkedId($markedId)
				->setDateLastActivity($lastActivity)
			;

			(new UnreadChat($chat, $userId, $counters->getByUserId($userId), $item))->send();
		}
	}
}

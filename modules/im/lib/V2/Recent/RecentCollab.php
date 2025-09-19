<?php

namespace Bitrix\Im\V2\Recent;

use Bitrix\Im\Model\EO_Recent_Collection;
use Bitrix\Im\Model\RecentTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Message\CounterService;
use Bitrix\Main\Type\DateTime;

class RecentCollab extends Recent
{
	public static function getCollabs(int $limit, ?DateTime $lastMessageDate = null): self
	{
		$recent = new static();
		$userId = $recent->getContext()->getUserId();
		$recentEntities = static::getOrmEntities($limit, $userId, $lastMessageDate);

		$chatIds = $recentEntities->getItemCidList();
		$counters = (new CounterService($userId))->getForEachChat($chatIds);

		foreach ($recentEntities as $entity)
		{
			$recentItem = new RecentItem();
			$recentItem
				->setMessageId($entity->getItemMid())
				->setChatId($entity->getItemCid())
				->setDialogId('chat' . $entity->getItemCid())
				->setCounter($counters[$entity->getItemCid()] ?? 0)
				->setUnread($entity->getUnread())
				->setPinned($entity->getPinned())
				->setLastReadMessageId($entity->getRelation()->getLastId())
				->setDateUpdate($entity->getDateUpdate())
				->setDateLastActivity($entity->getDateLastActivity())
			;
			$recent[] = $recentItem;
		}

		return $recent;
	}

	protected static function getOrmEntities(int $limit, int $userId, ?DateTime $lastMessageDate = null): EO_Recent_Collection
	{
		$query = RecentTable::query()
			->setSelect([
				'ITEM_CID',
				'ITEM_MID',
				'UNREAD',
				'PINNED',
				'DATE_LAST_ACTIVITY',
				'DATE_UPDATE',
				'RELATION.LAST_ID',
			])
			->where('USER_ID', $userId)
			->where('ITEM_TYPE', Chat::IM_TYPE_COLLAB)
			->setLimit($limit)
			->setOrder(self::getOrder($userId))
		;

		if (isset($lastMessageDate))
		{
			$query->where('DATE_LAST_ACTIVITY', '<=', $lastMessageDate);
		}

		return $query->fetchCollection();
	}
}

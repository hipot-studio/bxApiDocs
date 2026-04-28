<?php

namespace Bitrix\Im\V2\Sync\Recent;

use Bitrix\Im\Model\RecentTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Recent\Query\RecentFilter;
use Bitrix\Im\V2\Recent\Query\RecentParams;
use Bitrix\Im\V2\Recent\Recent;
use Bitrix\Im\V2\Rest\PopupData;
use Bitrix\Im\V2\Service\Locator;

class RecentSync extends Recent
{
	public static function getRecentSync(array $chatIds): self
	{
		if (empty($chatIds))
		{
			return new static();
		}

		$userId = Locator::getContext()->getUserId();

		$filter = [];
		$filter['userId'] = $userId;
		$filter['chatIds'] = $chatIds;
		$queryFilter = RecentFilter::fromArray($filter);

		$recentEntities = static::getSyncRecentEntities(filter: $queryFilter);
		return static::initByArray($recentEntities);
	}

	protected static function getSyncRecentEntities(RecentFilter $filter): array
	{
		$query = RecentTable::query();

		$query->setSelect([
			'ITEM_TYPE',
			'ITEM_ID',
			'ITEM_CID',
			'ITEM_MID',
			'UNREAD',
			'PINNED',
			'DATE_LAST_ACTIVITY',
			'DATE_UPDATE',
			'MARKED_ID',
		]);

		$params = new RecentParams($filter);
		$params->apply($query);

		return $query->fetchAll();
	}

	public static function initByArray(array $recentArray): static
	{
		$recent = new static();

		foreach ($recentArray as $entity)
		{
			$recentItem = new RecentSyncItem();
			$recentItem
				->setMessageId((int)$entity['ITEM_MID'])
				->setChatId((int)$entity['ITEM_CID'])
				->setDialogId(self::getDialogId((int)$entity['ITEM_ID'], $entity['ITEM_TYPE']))
				->setUnread(($entity['UNREAD'] ?? 'N') === 'Y')
				->setPinned(($entity['PINNED'] ?? 'N') === 'Y')
				->setDateUpdate($entity['DATE_UPDATE'] ?? null)
				->setDateLastActivity($entity['DATE_LAST_ACTIVITY'] ?? null)
				->setMarkedId($entity['MARKED_ID'] ?? 0)
			;
			$recent[] = $recentItem;
		}

		return $recent;
	}

	public function getEntityIds(): array
	{
		$chatIds = [];
		$messageIds = [];
		$dialogIds = [];

		foreach ($this as $entity)
		{
			$chatIds[$entity->getChatId()] = $entity->getChatId();
			$dialogIds[$entity->getChatId()] = $entity->getDialogId();
			$messageIds[$entity->getMessageId()] = $entity->getMessageId();
		}

		return [
			'chatIds' => $chatIds,
			'messageIds' => $messageIds,
			'dialogIds' => $dialogIds,
		];
	}

	protected static function getDialogId(int $itemId, string $itemType): string
	{
		if ($itemType === Chat::IM_TYPE_PRIVATE)
		{
			return (string)$itemId;
		}

		return 'chat' . $itemId;
	}

	public function getPopupData(array $excludedList = []): PopupData
	{
		return new PopupData([], $excludedList);
	}
}

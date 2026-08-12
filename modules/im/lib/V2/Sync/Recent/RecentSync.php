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
		$recent = static::initByArray($recentEntities);
		$recent->enrichCollabPreviewSources($userId);

		return $recent;
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
			'PREVIEW_SOURCE_CID',
			'PREVIEW_SOURCE_MID',
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
				// Keep the row's own last message: enrichCollabPreviewSources() may redirect messageId to a source, but not ownMessageId.
				->setOwnMessageId((int)$entity['ITEM_MID'])
				->setChatId((int)$entity['ITEM_CID'])
				->setDialogId(self::getDialogId((int)$entity['ITEM_ID'], $entity['ITEM_TYPE']))
				->setUnread(($entity['UNREAD'] ?? 'N') === 'Y')
				->setPinned(($entity['PINNED'] ?? 'N') === 'Y')
				->setDateUpdate($entity['DATE_UPDATE'] ?? null)
				->setDateLastActivity($entity['DATE_LAST_ACTIVITY'] ?? null)
				->setMarkedId($entity['MARKED_ID'] ?? 0)
				->setPreviewSourceCid((int)($entity['PREVIEW_SOURCE_CID'] ?? 0))
				->setPreviewSourceMid((int)($entity['PREVIEW_SOURCE_MID'] ?? 0))
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

			// The source chat is hydrated via Chats::mergeRecentChats() from sourceChatId, not here: it has no recent row of its own and must not leak into addedRecent.
			$messageIds[$entity->getMessageId()] = $entity->getMessageId();

			// When messageId was redirected to a source, also load the row's own last message.
			$ownMessageId = $entity->getOwnMessageId();
			if ($ownMessageId > 0 && $ownMessageId !== $entity->getMessageId())
			{
				$messageIds[$ownMessageId] = $ownMessageId;
			}
		}

		return [
			'chatIds' => $chatIds,
			'messageIds' => $messageIds,
			'dialogIds' => $dialogIds,
		];
	}

	/**
	 * Redirects collab recent rows to their materialized preview source: messageId to PREVIEW_SOURCE_MID
	 * and the source-chat hint to PREVIEW_SOURCE_CID. A disabled feature or a 0 pointer leaves the row as is.
	 *
	 * $userId is kept only for signature stability with the V2 read-path; the pointer is materialized on the row.
	 */
	public function enrichCollabPreviewSources(int $userId): void
	{
		unset($userId);

		if (!\Bitrix\Im\V2\Application\Features::isCollabPreviewSourceEnabled())
		{
			return;
		}

		foreach ($this as $item)
		{
			if (!$item instanceof RecentSyncItem)
			{
				continue;
			}

			$sourceMessageId = $item->getPreviewSourceMid();
			if ($sourceMessageId <= 0)
			{
				continue;
			}

			$item->setMessageId($sourceMessageId);

			$sourceChatId = $item->getPreviewSourceCid();
			if ($sourceChatId > 0)
			{
				$item->setSourceChatId($sourceChatId);
			}
		}
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

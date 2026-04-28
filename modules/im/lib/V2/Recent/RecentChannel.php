<?php

namespace Bitrix\Im\V2\Recent;

use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\Model\RecentTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Recent\Query\RecentFilter;
use Bitrix\Im\V2\Recent\Query\RecentParams;
use Bitrix\Im\V2\Service\Locator;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;

class RecentChannel extends Recent
{
	public static function getOpenChannels(int $limit, array $filter = []): self
	{
		$userId = Locator::getContext()->getUserId();

		$filter['userId'] = $userId;
		$recentFilter = RecentFilter::fromArray($filter);
		$recentParams = new RecentParams(
			filter: $recentFilter,
			limit: $limit
		);

		$chatEntities = static::getRecentEntities($recentParams);
		return static::initByArray($chatEntities);
	}

	public static function getRecentEntities(RecentParams $recentParams): array
	{
		$userId = $recentParams->filter?->userId ?? 0;
		$lastMessageId = $recentParams->filter?->lastMessageId;

		$query = ChatTable::query()
			->setSelect(['ID', 'LAST_MESSAGE_ID', 'PINNED' => 'RECENT.PINNED'])
			->where('TYPE', Chat::IM_TYPE_OPEN_CHANNEL)
			->registerRuntimeField(new Reference(
					'RECENT',
					RecentTable::class,
					Join::on('this.ID', 'ref.ITEM_ID')
						->where('ref.ITEM_TYPE', Chat::IM_TYPE_OPEN_CHANNEL)
						->where('ref.USER_ID', $userId),
				)
			)
			->setLimit($recentParams->limit)
			->setOrder(['LAST_MESSAGE_ID' => 'DESC'])
		;

		if (isset($lastMessageId))
		{
			$query->where('LAST_MESSAGE_ID', '<', $lastMessageId);
		}
		if ($recentParams->filter->parentChatId !== null)
		{
			$query->where('PARENT_ID', $recentParams->filter->parentChatId);
		}

		return $query->fetchAll();
	}

	public static function initByArray(array $recentArray): static
	{
		$recent = new static();

		foreach ($recentArray as $entity)
		{
			$recentItem = new RecentItem();
			$recentItem
				->setMessageId((int)$entity['LAST_MESSAGE_ID'])
				->setChatId((int)$entity['ID'])
				->setDialogId('chat' . $entity['ID'])
				->setPinned($entity['PINNED'] === 'Y')
			;
			$recent[] = $recentItem;
		}

		return $recent;
	}
}

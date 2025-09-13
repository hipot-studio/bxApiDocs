<?php

namespace Bitrix\Im\V2\Recent;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Message\MessagePopupItem;
use Bitrix\Im\V2\Registry;
use Bitrix\Im\V2\Rest\PopupData;
use Bitrix\Im\V2\Rest\PopupDataAggregatable;
use Bitrix\Im\V2\Rest\PopupDataItem;

/**
 * @extends Registry<RecentItem>
 */
class Recent extends Registry implements PopupDataAggregatable, PopupDataItem
{
	use ContextCustomer;

	public function getPopupData(array $excludedList = []): PopupData
	{
		$messageIds = [];
		$chats = [];
		$chatIds = [];

		foreach ($this as $item)
		{
			$messageIds[] = $item->getMessageId();
			$chats[] = Chat::getInstance($item->getChatId());
			$chatIds[] = $item->getChatId();
		}

		return new PopupData([
			new MessagePopupItem($messageIds, true),
			new Chat\ChatPopupItem($chats),
			new BirthdayPopupItem(),
			new Chat\MessagesAutoDelete\MessagesAutoDeleteConfigs($chatIds),
		], $excludedList);
	}

	final public static function getRestEntityName(): string
	{
		return 'recentItems';
	}

	public function toRestFormat(array $option = []): array
	{
		$rest = [];

		foreach ($this as $item)
		{
			$rest[] = $item->toRestFormat();
		}

		return $rest;
	}

	public function merge(PopupDataItem $item): PopupDataItem
	{
		return $this;
	}
}
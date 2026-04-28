<?php

namespace Bitrix\Im\V2\Reading\Counter\Notification;

use Bitrix\Im\Model\MessageUnreadTable;
use Bitrix\Im\V2\Common\RowsToMapHelper;
use Bitrix\Im\V2\Message\Counter\CounterOverflowService;
use Bitrix\Im\V2\Message\Counter\NotificationsCounterOverflowInfo;
use Bitrix\Im\V2\Notification\ChatProvider;
use Bitrix\Im\V2\Reading\Counter\Entity\UsersCounterMap;
use Bitrix\Main\ORM\Fields\ExpressionField;

class CountersProvider
{
	public function __construct(
		private readonly CounterOverflowService $overflowService,
	) {}

	public function getForUsers(array $userIds): UsersCounterMap
	{
		$overflowInfo = $this->overflowService->getOverflowedNotifications($userIds);
		$counters = $this->fetchByChatIds($overflowInfo->getWithoutOverflow());
		$normalizedCounters = $this->normalizeCounters($counters, $userIds, $overflowInfo);

		return UsersCounterMap::fromArray($normalizedCounters);
	}

	private function fetchByChatIds(array $notificationChatIds): array
	{
		if (empty($notificationChatIds))
		{
			return [];
		}

		$query = MessageUnreadTable::query()
			->setSelect(['USER_ID', 'COUNT'])
			->setGroup(['USER_ID'])
			->whereIn('CHAT_ID', $notificationChatIds)
			->registerRuntimeField('COUNT', new ExpressionField('COUNT', 'COUNT(*)'))
		;

		return RowsToMapHelper::mapIntToInt($query->fetchAll(), 'USER_ID', 'COUNT');
	}

	private function normalizeCounters(
		array $counters,
		array $userIds,
		NotificationsCounterOverflowInfo $overflowInfo
	): array
	{
		foreach ($userIds as $userId)
		{
			if ($overflowInfo->hasOverflow($userId))
			{
				$counters[$userId] = $this->overflowService::getOverflowValue();
			}
			if (!isset($counters[$userId]))
			{
				$counters[$userId] = 0;
			}
		}

		return $counters;
	}
}

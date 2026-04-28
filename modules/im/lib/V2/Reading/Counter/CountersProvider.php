<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Counter;

use Bitrix\Im\Model\MessageUnreadTable;
use Bitrix\Im\V2\Common\RowsToMapHelper;
use Bitrix\Im\V2\Message\Counter\CounterOverflowInfo;
use Bitrix\Im\V2\Message\Counter\CounterOverflowService;
use Bitrix\Im\V2\Reading\Counter\Entity\ChatsCounterMap;
use Bitrix\Im\V2\Reading\Counter\Entity\UsersCounterMap;
use Bitrix\Im\V2\Reading\Counter\Internal\CountersCache;
use Bitrix\Main\ORM\Fields\ExpressionField;

class CountersProvider
{
	public function __construct(
		private readonly CounterOverflowService $overflowService,
		private readonly CountersCache $cache,
	)
	{}

	public function getForUsers(int $chatId, array $userIds): UsersCounterMap
	{
		$overflowInfo = $this->overflowService->getOverflowInfo($userIds, $chatId);
		$counters = $this->fetchByChatIdAndUserIds($chatId, $overflowInfo->getUsersWithoutOverflow());
		$normalizedCounters = $this->normalizeCounters($counters, $userIds, $overflowInfo);

		return UsersCounterMap::fromArray($normalizedCounters);
	}

	public function getForUser(int $chatId, int $userId): int
	{
		$cached = $this->cache->getChatCounter($userId, $chatId);
		if ($cached !== null)
		{
			return $cached;
		}

		$counter = $this->getForUsers($chatId, [$userId])->getByUserId($userId);
		$this->cache->setChatCounter($userId, $chatId, $counter);

		return $counter;
	}

	/**
	 * @internal
	 */
	public function getForUserByChatIds(int $userId, array $chatIds): ChatsCounterMap
	{
		$result = [];
		$unknown = [];
		foreach ($chatIds as $chatId)
		{
			$cached = $this->cache->getChatCounter($userId, $chatId);
			if ($cached !== null)
			{
				$result[$chatId] = $cached;
			}
			else
			{
				$unknown[] = $chatId;
			}
		}

		$unknownWithOverflow = $this->overflowService->filterOverflowedChatIdsByUserId($unknown, $userId);
		$unknownWithoutOverflow = array_diff($unknown, $unknownWithOverflow);
		$fetched = $this->fetchByUserIdAndChatIds($userId, $unknownWithoutOverflow);
		$fetched = $this->normalizeUserCounters($unknown, $fetched, $unknownWithOverflow);
		foreach ($fetched as $chatId => $counter)
		{
			$result[$chatId] = $counter;
			$this->cache->setChatCounter($userId, $chatId, $counter);
		}

		return ChatsCounterMap::fromArray($result);
	}

	public function getUnreadStatuses(array $messageIds, int $userId): array
	{
		if (empty($messageIds))
		{
			return [];
		}

		$query = MessageUnreadTable::query()
			->setSelect(['MESSAGE_ID'])
			->whereIn('MESSAGE_ID', $messageIds)
			->where('USER_ID', $userId)
		;

		$result = [];
		$unreadMessages = RowsToMapHelper::mapIntToInt($query->fetchAll(), 'MESSAGE_ID', 'MESSAGE_ID');
		foreach ($messageIds as $messageId)
		{
			$result[$messageId] = isset($unreadMessages[$messageId]);
		}

		return $result;
	}

	private function fetchByChatIdAndUserIds(int $chatId, array $userIds): array
	{
		$query = MessageUnreadTable::query()
			->setSelect(['USER_ID', 'COUNT'])
			->where('CHAT_ID', $chatId)
			->setGroup(['USER_ID'])
			->registerRuntimeField('COUNT', new ExpressionField('COUNT', 'COUNT(*)'))
		;
		if (!empty($userIds))
		{
			$query->whereIn('USER_ID', $userIds);
		}

		return RowsToMapHelper::mapIntToInt($query->fetchAll(), 'USER_ID', 'COUNT');
	}

	private function fetchByUserIdAndChatIds(int $userId, array $chatIds): array
	{
		if (empty($chatIds))
		{
			return [];
		}

		$query = MessageUnreadTable::query()
			->setSelect(['CHAT_ID', 'COUNT'])
			->where('USER_ID', $userId)
			->whereIn('CHAT_ID', $chatIds)
			->setGroup(['CHAT_ID'])
			->registerRuntimeField('COUNT', new ExpressionField('COUNT', 'COUNT(*)'))
		;

		return RowsToMapHelper::mapIntToInt($query->fetchAll(), 'CHAT_ID', 'COUNT');
	}

	private function normalizeCounters(array $counters, array $users, CounterOverflowInfo $overflowInfo): array
	{
		foreach ($users as $userId)
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

	private function normalizeUserCounters(array $requested, array $fetched, array $overflowed): array
	{
		$overflowedMap = array_fill_keys($overflowed, true);
		foreach ($requested as $chatId)
		{
			if (isset($overflowedMap[$chatId]))
			{
				$fetched[$chatId] = $this->overflowService::getOverflowValue();
			}
			if (!isset($fetched[$chatId]))
			{
				$fetched[$chatId] = 0;
			}
		}

		return $fetched;
	}
}

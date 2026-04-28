<?php

namespace Bitrix\Im\V2\Message\Counter;

use Bitrix\Im\Model\CounterOverflowTable;
use Bitrix\Im\V2\Notification\ChatProvider;
use Bitrix\Im\V2\Reading\Counter\Entity\UsersCounterMap;

class CounterOverflowService
{
	protected const PARTIAL_INSERT_ROWS = 500;

	/**
	 * @var CounterOverflowInfo[]
	 */
	protected static array $overflowInfoStaticCache = [];
	protected static int $overflowValue = 100;

	public function __construct(
		private readonly ChatProvider $notificationChatProvider,
	) {}

	public function insertOverflowed(UsersCounterMap $counters, int $chatId): void
	{
		$overflowedCounters = $this->filterOverflowedCounters($counters);
		$this->insert($overflowedCounters->getUserIds(), $chatId);
		foreach ($overflowedCounters->getUserIds() as $userId)
		{
			self::cleanCacheByChatId($chatId, $userId, true);
		}
	}

	public function getOverflowInfo(array $userIds, int $chatId): CounterOverflowInfo
	{
		$infoFromCache = $this->getOverflowInfoFromCache($userIds, $chatId);
		if ($infoFromCache)
		{
			return $infoFromCache;
		}

		$usersWithOverflowedCounters = $this->getUsersWithOverflow($userIds, $chatId);
		$usersWithoutOverflowedCounters = $this->filterUsersWithoutOverflow($usersWithOverflowedCounters, $userIds);
		$overflowInfo = new CounterOverflowInfo(
			$usersWithOverflowedCounters,
			$usersWithoutOverflowedCounters,
			$chatId
		);
		self::$overflowInfoStaticCache[$chatId] = $overflowInfo;

		return $overflowInfo;
	}

	public function getOverflowedNotifications(array $userIds): NotificationsCounterOverflowInfo
	{
		$cached = [];
		$fetched = [];
		$needToFetch = [];
		$this->notificationChatProvider->preload($userIds);
		foreach ($userIds as $userId)
		{
			$chatId = $this->notificationChatProvider->getChatId($userId);
			if (isset(self::$overflowInfoStaticCache[$chatId]))
			{
				$cached[$userId] = self::$overflowInfoStaticCache[$chatId];
			}
			else
			{
				$needToFetch[$userId] = $chatId;
			}
		}

		$raw = [];
		if (!empty($needToFetch))
		{
			$raw = CounterOverflowTable::query()
				->setSelect(['CHAT_ID', 'USER_ID'])
				->whereIn('CHAT_ID', $needToFetch)
				->fetchAll()
			;
		}
		foreach ($raw as $row)
		{
			$userId = (int)$row['USER_ID'];
			$fetched[$userId] = new CounterOverflowInfo([$userId => $userId], [], (int)$row['CHAT_ID']);
		}
		foreach ($userIds as $userId)
		{
			$chatId = $this->notificationChatProvider->getChatId($userId);
			$fetched[$userId] ??= new CounterOverflowInfo([], [$userId => $userId], $chatId);
		}

		foreach ($fetched as $userId => $info)
		{
			self::$overflowInfoStaticCache[$info->getChatId()] = $info;
		}

		return NotificationsCounterOverflowInfo::fromCounterOverflowInfo(array_merge($cached, $fetched));
	}

	public function filterOverflowedChatIdsByUserId(array $chatIds, int $userId): array
	{
		if (empty($chatIds))
		{
			return [];
		}

		$rows = CounterOverflowTable::query()
			->setSelect(['CHAT_ID'])
			->whereIn('CHAT_ID', $chatIds)
			->where('USER_ID', $userId)
			->fetchAll()
		;

		return array_map('intval', array_column($rows, 'CHAT_ID'));
	}

	public function getOverflowedChatIdsByUserId(int $userId, int $limit): array
	{
		$rows = CounterOverflowTable::query()
			->setSelect(['CHAT_ID'])
			->where('USER_ID', $userId)
			->setLimit($limit)
			->fetchAll()
		;

		return array_map('intval', array_column($rows, 'CHAT_ID'));
	}

	public function insertNotificationOverflow(UsersCounterMap $counters): void
	{
		$this->notificationChatProvider->preload($counters->getUserIds());
		$overflowedCounters = $this->filterOverflowedCounters($counters);
		$rows = [];
		foreach ($overflowedCounters->getUserIds() as $userId)
		{
			$chatId = $this->notificationChatProvider->getChatId($userId);
			$rows[] = $this->getRowToInsert($userId, $chatId);
		}
		$this->insertRows($rows);
		foreach ($overflowedCounters->getUserIds() as $userId)
		{
			$chatId = $this->notificationChatProvider->getChatId($userId);
			self::cleanCacheByChatId($chatId, $userId, true);
		}
	}

	protected function getOverflowInfoFromCache(array $userIds, int $chatId): ?CounterOverflowInfo
	{
		if (!isset(self::$overflowInfoStaticCache[$chatId]))
		{
			return null;
		}

		$info = self::$overflowInfoStaticCache[$chatId];
		foreach ($userIds as $userId)
		{
			if (!$info->has($userId))
			{
				return null;
			}
		}

		return $info;
	}

	public static function getOverflowValue(): int
	{
		return self::$overflowValue;
	}

	public function delete(int $userId, int $chatId): void
	{
		CounterOverflowTable::deleteByFilter(['=CHAT_ID' => $chatId, '=USER_ID' => $userId]);
		self::cleanCacheByChatId($chatId, $userId);
	}

	public static function deleteByChatIdForAll(int $chatId): void
	{
		CounterOverflowTable::deleteByFilter(['=CHAT_ID' => $chatId]);
		self::cleanCacheByChatId($chatId);
	}

	public static function deleteByChatIds(array $chatIds, ?int $userId = null): void
	{
		if (empty($chatIds))
		{
			return;
		}

		$filter = ['=CHAT_ID' => $chatIds];
		if (isset($userId))
		{
			$filter['=USER_ID'] = $userId;
		}

		CounterOverflowTable::deleteByFilter($filter);
		foreach ($chatIds as $chatId)
		{
			self::cleanCacheByChatId($chatId, $userId);
		}
	}

	public static function deleteByScope(?array $chatIds = null, ?int $userId = null): void
	{
		$filter = [];

		if ($chatIds !== null)
		{
			$filter['=CHAT_ID'] = $chatIds;
		}

		if ($userId !== null)
		{
			$filter['=USER_ID'] = $userId;
		}

		if (empty($filter))
		{
			return;
		}

		CounterOverflowTable::deleteByFilter($filter);

		self::cleanCache($chatIds, $userId);
	}

	public static function deleteAllByUserId(int $userId): void
	{
		CounterOverflowTable::deleteByFilter(['=USER_ID' => $userId]);
		self::cleanCache(userId: $userId);
	}

	/**
	 * Deletes overflow counter records and cache for the provided [user => chat] map.
	 * Uses deleteByScope in a loop for better index utilization.
	 * Format [userId => [chatId, chatId, ...]]
	 * @param array<int, array<int>> $overflowMap
	 */
	public static function deleteBatch(array $overflowMap): void
	{
		if (empty($overflowMap))
		{
			return;
		}

		foreach ($overflowMap as $userId => $chatIds)
		{
			if (empty($chatIds))
			{
				continue;
			}

			self::deleteByScope($chatIds, (int)$userId);
		}
	}

	protected function getUsersWithOverflow(array $userIds, int $chatId): array
	{
		$result = [];
		if (empty($userIds))
		{
			return [];
		}

		$raw = CounterOverflowTable::query()
			->setSelect(['USER_ID'])
			->where('CHAT_ID', $chatId)
			->whereIn('USER_ID', $userIds)
			->exec()
		;

		foreach ($raw as $row)
		{
			$userId = (int)$row['USER_ID'];
			$result[$userId] = $userId;
		}

		return $result;
	}

	protected function filterUsersWithoutOverflow(array $overflowedUsers, array $allUsers): array
	{
		return array_filter($allUsers, static fn (int $userId) => !isset($overflowedUsers[$userId]));
	}

	protected function filterOverflowedCounters(UsersCounterMap $counters): UsersCounterMap
	{
		return $counters->filter(static fn (int $counter): bool => $counter >= self::$overflowValue);
	}

	protected function insert(array $userIds, int $chatId): void
	{
		if (empty($userIds))
		{
			return;
		}

		$rows = array_map(fn (int $userId) => $this->getRowToInsert($userId, $chatId), $userIds);

		$this->insertRows($rows);
	}

	protected function insertRows(array $rows): void
	{
		foreach (array_chunk($rows, self::PARTIAL_INSERT_ROWS, true) as $part)
		{
			CounterOverflowTable::multiplyInsertWithoutDuplicate(
				$part,
				[
					'DEADLOCK_SAFE' => true,
					'UNIQUE_FIELDS' => ['CHAT_ID', 'USER_ID'],
				]
			);
		}
	}

	protected function getRowToInsert(int $userId, int $chatId): array
	{
		return ['CHAT_ID' => $chatId, 'USER_ID' => $userId];
	}

	protected static function cleanCache(?array $chatIds = null, ?int $userId = null, bool $hasOverflowNow = false): void
	{
		if ($chatIds === null)
		{
			if ($userId === null)
			{
				self::$overflowInfoStaticCache = [];
				return;
			}

			$chatIds = array_keys(self::$overflowInfoStaticCache);
		}

		foreach ($chatIds as $chatId)
		{
			self::cleanCacheByChatId($chatId, $userId, $hasOverflowNow);
		}
	}

	protected static function cleanCacheByChatId(int $chatId, ?int $userId = null, bool $hasOverflowNow = false): void
	{
		if (!isset(self::$overflowInfoStaticCache[$chatId]))
		{
			return;
		}

		if ($userId === null)
		{
			unset(self::$overflowInfoStaticCache[$chatId]);

			return;
		}

		$wasOverflowed = self::$overflowInfoStaticCache[$chatId]->hasOverflow($userId);

		if ($wasOverflowed !== $hasOverflowNow)
		{
			self::$overflowInfoStaticCache[$chatId]->changeOverflowStatus($userId, $hasOverflowNow);
		}
	}
}

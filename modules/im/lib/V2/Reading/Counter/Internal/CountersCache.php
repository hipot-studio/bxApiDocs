<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Counter\Internal;

use Bitrix\Im\V2\Chat\CopilotChat;
use Bitrix\Im\V2\Message\CounterService;
use Bitrix\Im\V2\Reading\Counter\Entity\UserCounters;
use Bitrix\Main\Data\Cache;

class CountersCache
{
	private const CACHE_DIR = '/bx/im/counter/v8/';
	private const CACHE_KEY_PREFIX = 'user_counters_';
	private const CACHE_TTL = 86400;

	protected array $inMemoryCache = [];
	protected array $inMemoryPointCache = [];

	public function get(int $userId): ?UserCounters
	{
		if (isset($this->inMemoryCache[$userId]))
		{
			return $this->inMemoryCache[$userId];
		}

		$cache = Cache::createInstance();
		$cache->initCache(self::CACHE_TTL, $this->getCacheKey($userId), self::CACHE_DIR);
		$counters = $cache->getVars();
		if ($counters !== false)
		{
			return $this->inMemoryCache[$userId] = $counters;
		}

		return null;
	}

	public function set(int $userId, UserCounters $counters): void
	{
		$this->inMemoryCache[$userId] = $counters;
		$cache = Cache::createInstance();
		$cache->startDataCache(self::CACHE_TTL, $this->getCacheKey($userId), self::CACHE_DIR);
		$cache->endDataCache($counters);
	}

	public function getChatCounter(int $userId, int $chatId): ?int
	{
		if (isset($this->inMemoryPointCache[$userId][$chatId]))
		{
			return $this->inMemoryPointCache[$userId][$chatId];
		}

		$userCounters = $this->get($userId);

		return $userCounters?->getByChatId($chatId)?->counter;

	}

	public function setChatCounter(int $userId, int $chatId, int $counter): void
	{
		$this->inMemoryPointCache[$userId][$chatId] = $counter;
	}

	public function remove(int $userId): void
	{
		$cache = Cache::createInstance();
		$cache->clean($this->getCacheKey($userId), self::CACHE_DIR);
		unset($this->inMemoryCache[$userId], $this->inMemoryPointCache[$userId]);

		CounterService::clearLegacyCache($userId);
	}

	public function removeAll(): void
	{
		$this->inMemoryCache = [];
		$this->inMemoryPointCache = [];
		$cache = Cache::createInstance();
		$cache->cleanDir(self::CACHE_DIR);

		CounterService::clearLegacyCache();
	}

	private function getCacheKey(int $userId): string
	{
		return self::CACHE_KEY_PREFIX . $userId . $this->buildCacheKeySuffix($this->getCacheKeyContext());
	}

	// NOTE: If the Copilot feature flag flips on/off/on within the TTL, an outdated cache entry may be served.
	// TODO: Consider keeping the user's last cache key in a separate cache entry to invalidate the previous variant on change.
	private function getCacheKeyContext(): array
	{
		return [
			'cpl' => (int)CopilotChat::isActive(),
		];
	}

	private function buildCacheKeySuffix(array $context): string
	{
		ksort($context);

		$suffix = '';
		foreach ($context as $name => $value)
		{
			$suffix .= "_{$name}{$value}";
		}

		return $suffix;
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\EventLog;

use Bitrix\Main\Application;

class PendingCache
{
	private const TTL = 60;
	private const DIR = '/bx/im/event/pending/';

	public function has(int $userId): bool
	{
		$cache = Application::getInstance()->getCache();

		return $cache->initCache(self::TTL, $this->getCacheKey($userId), self::DIR);
	}

	public function set(int $userId): void
	{
		$cache = Application::getInstance()->getCache();
		$cache->initCache(self::TTL, $this->getCacheKey($userId), self::DIR);
		$cache->startDataCache();
		$cache->endDataCache(true);
	}

	public function invalidate(int $userId): void
	{
		$cache = Application::getInstance()->getCache();
		$cache->clean($this->getCacheKey($userId), self::DIR);
	}

	private function getCacheKey(int $userId): string
	{
		return 'event_pending_' . $userId;
	}
}

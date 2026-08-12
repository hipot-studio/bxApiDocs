<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\NewApps;

use Bitrix\Main\Data\Cache;

/**
 * Per-user cache for the "new apps" badge count.
 *
 * The count is recomputed synchronously on every page render (the binder
 * extension is loaded in OnEpilog), so it is cached to keep that hot path off
 * a heavy personal COUNT on each hit. The user-facing AJAX recount bypasses the
 * cache for accuracy, and opening an app forgets the entry.
 */
final class NewAppsCountCache
{
	private const TTL = 300;
	private const DIR = '/vibecodeconnector/new_apps_count';

	/**
	 * @param callable():int $compute
	 */
	public function remember(int $userId, callable $compute): int
	{
		$cache = Cache::createInstance();
		if ($cache->initCache(self::TTL, $this->key($userId), self::DIR))
		{
			return (int)$cache->getVars();
		}

		$value = $compute();
		if ($cache->startDataCache())
		{
			$cache->endDataCache($value);
		}

		return $value;
	}

	public function forget(int $userId): void
	{
		Cache::createInstance()->clean($this->key($userId), self::DIR);
	}

	private function key(int $userId): string
	{
		return 'u' . $userId;
	}
}

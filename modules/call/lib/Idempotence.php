<?php

namespace Bitrix\Call;

use Bitrix\Main\Data\Cache;
use Bitrix\Main\Data\Cache\KeyValueEngine;

/**
 * @internal
 */
class Idempotence
{
	private const
		CACHE_TTL = 86400,
		CACHE_DIR = '/call/idempotence';

	/**
	 * Atomic claim-or-reject for an idempotency key.
	 * Returns true if this caller just claimed the key, false if it was
	 * already taken (the request is a duplicate retry).
	 *
	 * Backed by {@see KeyValueEngine::setNotExists()} (SETNX) when a KV
	 * cache engine is configured (Redis/Memcache/Memcached/APC) — atomic
	 * across PHP-FPM workers so two concurrent requests with the same
	 * requestId can never both observe "unclaimed". Falls back to
	 * {@see Cache::startDataCache()} on file-cache deployments: read-then-
	 * write split is non-atomic, but the window collapses to microseconds
	 * compared with the legacy isUnique-then-addKey-tens-of-seconds-later
	 * pattern.
	 *
	 * @param string $key
	 * @param int $ttl Cache TTL in seconds, defaults to self::CACHE_TTL (24h).
	 * @return bool true if the key was claimed (first call), false if it already exists.
	 */
	public static function addKey(string $key, int $ttl = self::CACHE_TTL): bool
	{
		$engine = self::getKvCacheEngine();
		if ($engine !== null)
		{
			return $engine->setNotExists(self::engineKey($key), $ttl, '1');
		}

		$cache = Cache::createInstance();
		if (!$cache->startDataCache($ttl, self::getCacheKey($key), self::CACHE_DIR))
		{
			return false;
		}
		$cache->endDataCache(['key' => $key]);
		return true;
	}

	/**
	 * Drops a previously stored key. Mainly used by tests/bench harness to reset state.
	 *
	 * @param string $key
	 * @return void
	 */
	public static function clearKey(string $key): void
	{
		$engine = self::getKvCacheEngine();
		if ($engine !== null)
		{
			$engine->del(self::engineKey($key));
			return;
		}

		$cache = Cache::createInstance();
		$cache->clean(self::getCacheKey($key), self::CACHE_DIR);
	}

	private static function getKvCacheEngine(): ?KeyValueEngine
	{
		$engine = Cache::createCacheEngine();

		return $engine instanceof KeyValueEngine ? $engine : null;
	}

	private static function getCacheKey(string $key): string
	{
		return md5($key);
	}

	private static function engineKey(string $key): string
	{
		$cacheConfig = \Bitrix\Main\Config\Configuration::getValue('cache');
		$sid = (is_array($cacheConfig) && !empty($cacheConfig['sid'])) ? $cacheConfig['sid'] : 'BX';

		return $sid . '|call:idempotence:' . self::getCacheKey($key);
	}
}

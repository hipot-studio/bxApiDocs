<?php

namespace Bitrix\Call;

use Bitrix\Main\Data\Cache;
use Bitrix\Main\Data\Cache\KeyValueEngine;

/**
 * @internal
 */
class Idempotence
{
	/**
	 * Two-state idempotency value. A key transitions IN_FLIGHT -> DONE on the
	 * action's first successful pass via {@see self::markDone()}, or is
	 * removed via {@see self::clearKey()} on failure. The filter inspects the
	 * value to decide whether a concurrent retransmit can be safely
	 * short-circuited as a replay (DONE) or must wait (IN_FLIGHT).
	 */
	public const
		STATE_IN_FLIGHT = '1',
		STATE_DONE = '2'
	;

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
			return $engine->setNotExists(self::engineKey($key), $ttl, self::STATE_IN_FLIGHT);
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
	 * Promotes a previously claimed key from IN_FLIGHT to DONE. Called by
	 * the filter once the action body has completed successfully. Concurrent
	 * retransmits arriving AFTER this transition are short-circuited as
	 * replay; retransmits arriving while the original is still in-flight see
	 * IN_FLIGHT (or no readable state on no-KV installs) and get REJECTED
	 * via REQUEST_NOT_UNIQUE so they keep retrying — that prevents the race
	 * where a duplicate is acked as success while the original then fails
	 * and releases the key, losing the callback.
	 *
	 * No-op on file-cache fallback: state values are not readable there, so
	 * concurrent retransmits always go through the conservative
	 * REQUEST_NOT_UNIQUE branch — safe by default, just more retries on
	 * no-KV inst.
	 *
	 * @param string $key
	 * @param int $ttl Cache TTL in seconds, defaults to self::CACHE_TTL (24h).
	 * @return void
	 */
	public static function markDone(string $key, int $ttl = self::CACHE_TTL): void
	{
		$engine = self::getKvCacheEngine();
		if ($engine !== null)
		{
			$engine->set(self::engineKey($key), $ttl, self::STATE_DONE);
		}
	}

	/**
	 * Reads the current state of a claimed key, or null if the key does not
	 * exist / its value is not readable (file-cache fallback).
	 *
	 * @param string $key
	 * @return string|null one of self::STATE_* constants, or null.
	 */
	public static function getState(string $key): ?string
	{
		$engine = self::getKvCacheEngine();
		if ($engine !== null)
		{
			$value = $engine->get(self::engineKey($key));
			return is_string($value) ? $value : null;
		}
		return null;
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

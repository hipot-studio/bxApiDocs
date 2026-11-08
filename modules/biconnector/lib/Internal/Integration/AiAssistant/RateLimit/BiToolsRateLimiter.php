<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\RateLimit;

use Bitrix\Main\Data\Cache;

/**
 * Per-user sliding-window rate limiter for the AI-assistant BI tools.
 *
 * Every call to any tool of either BI hierarchy ({@see \Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\BaseBiTool}
 * and {@see \Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\BaseSubjectAwareBiTool}, read and write
 * alike) is counted against the same window. A user may make at
 * most {@see self::MAX_REQUESTS} calls within any {@see self::WINDOW_SECONDS}
 * window; the (MAX_REQUESTS + 1)-th call inside the window is rejected without
 * being registered, and the caller is told how many seconds to wait until the
 * oldest counted call leaves the window.
 *
 * On top of that shared window, destructive operations have a window of their
 * own: {@see self::consumeObject()} counts distinct objects a user destroys
 * within the same {@see self::WINDOW_SECONDS}, capped at
 * {@see self::DESTRUCTIVE_MAX_OBJECTS}. Repeating the same object costs nothing,
 * so a retry after a transport failure is never locked out.
 *
 * The window state (a list of Unix timestamps, or object key => first-seen
 * timestamp pairs for the destructive window) is stored in
 * {@see \Bitrix\Main\Data\Cache} with a TTL equal to the window, mirroring
 * {@see \Bitrix\AiAssistant\External\AiNode\Limiter\RateLimiter}.
 */
class BiToolsRateLimiter
{
	public const WINDOW_SECONDS = 60;
	public const MAX_REQUESTS = 60;
	public const DESTRUCTIVE_MAX_OBJECTS = 5;

	private const CACHE_DIR = '/biconnector/ai_tools_ratelimit/';
	private const DESTRUCTIVE_CACHE_DIR = '/biconnector/ai_tools_destructive_ratelimit/';

	/**
	 * Registers the current call for the given user and reports whether it is
	 * allowed under the sliding window.
	 *
	 * @param int $userId User whose calls are being counted.
	 * @return array{allowed: bool, retryAfter: int} `allowed` is false when the
	 *         limit is exceeded; `retryAfter` is the number of seconds until the
	 *         oldest counted call leaves the window (0 when allowed).
	 */
	public function consume(int $userId): array
	{
		$key = $this->getCacheKey($userId);
		$cacheDir = $this->getCacheDir($userId);
		$now = time();

		$timestamps = self::filterWithinWindow($this->read($key, $cacheDir), $now);

		if (count($timestamps) >= self::MAX_REQUESTS)
		{
			return [
				'allowed' => false,
				'retryAfter' => self::retryAfter($timestamps, $now),
			];
		}

		$timestamps[] = $now;
		$this->write($key, $cacheDir, $timestamps);

		return [
			'allowed' => true,
			'retryAfter' => 0,
		];
	}

	/**
	 * Registers a destructive operation on the given object and reports whether
	 * it is allowed under the destructive window.
	 *
	 * An object already inside the window passes regardless of how full the
	 * window is, and its first-seen timestamp is kept as is: refreshing it would
	 * let a repeated call extend the window indefinitely, and rejecting it would
	 * lock out the retry that recovers from a failed attempt.
	 *
	 * @param int $userId User whose destructive operations are being counted.
	 * @param string $objectKey Identifies the object across calls, e.g. `dashboard:12`.
	 * @return array{allowed: bool, retryAfter: int} Same shape as {@see self::consume()}.
	 */
	public function consumeObject(int $userId, string $objectKey): array
	{
		$key = $this->getDestructiveCacheKey($userId);
		$cacheDir = $this->getDestructiveCacheDir($userId);
		$now = time();

		$window = self::filterPairsWithinWindow($this->read($key, $cacheDir), $now);

		if (!array_key_exists($objectKey, $window))
		{
			if (count($window) >= self::DESTRUCTIVE_MAX_OBJECTS)
			{
				return [
					'allowed' => false,
					'retryAfter' => self::retryAfterPairs($window, $now),
				];
			}

			$window[$objectKey] = $now;
		}

		$this->write($key, $cacheDir, $window);

		return [
			'allowed' => true,
			'retryAfter' => 0,
		];
	}

	/**
	 * Drops timestamps that fall outside the current window.
	 *
	 * Pure helper, extracted so the windowing logic can be unit-tested without a
	 * cache backend.
	 *
	 * @param int[] $timestamps
	 * @param int $now
	 * @return int[] Re-indexed list of timestamps still inside the window.
	 */
	public static function filterWithinWindow(array $timestamps, int $now): array
	{
		$windowStart = $now - self::WINDOW_SECONDS;

		return array_values(array_filter(
			$timestamps,
			static fn($ts): bool => (int)$ts > $windowStart,
		));
	}

	/**
	 * Seconds until the window frees up, i.e. until the oldest counted call
	 * leaves the window. Always at least 1 for a full window.
	 *
	 * Pure helper, extracted for unit testing.
	 *
	 * @param int[] $timestamps Timestamps already filtered to the window.
	 * @param int $now
	 * @return int
	 */
	public static function retryAfter(array $timestamps, int $now): int
	{
		if (empty($timestamps))
		{
			return 0;
		}

		$oldest = min($timestamps);
		$retryAfter = ($oldest + self::WINDOW_SECONDS) - $now;

		return max(1, $retryAfter);
	}

	/**
	 * Drops object keys whose first appearance falls outside the current window.
	 *
	 * Pure helper, extracted for unit testing.
	 *
	 * @param array<string, int> $window Object key => first-seen timestamp.
	 * @param int $now
	 * @return array<string, int> Pairs still inside the window, keys preserved.
	 */
	public static function filterPairsWithinWindow(array $window, int $now): array
	{
		$windowStart = $now - self::WINDOW_SECONDS;

		return array_filter(
			$window,
			static fn($ts): bool => (int)$ts > $windowStart,
		);
	}

	/**
	 * Seconds until the destructive window frees up, i.e. until the object seen
	 * first leaves it.
	 *
	 * Pure helper, extracted for unit testing.
	 *
	 * @param array<string, int> $window Pairs already filtered to the window.
	 * @param int $now
	 * @return int
	 */
	public static function retryAfterPairs(array $window, int $now): int
	{
		return self::retryAfter(array_values($window), $now);
	}

	private function getCacheKey(int $userId): string
	{
		return 'bi_tools_rate_' . $userId;
	}

	private function getDestructiveCacheKey(int $userId): string
	{
		return 'bi_tools_destructive_rate_' . $userId;
	}

	private function getCacheDir(int $userId): string
	{
		return self::CACHE_DIR . $this->getUserShard($userId);
	}

	private function getDestructiveCacheDir(int $userId): string
	{
		return self::DESTRUCTIVE_CACHE_DIR . $this->getUserShard($userId);
	}

	private function getUserShard(int $userId): string
	{
		return substr(md5((string)$userId), 2, 2) . '/';
	}

	/**
	 * @return array Window state as it was stored: a timestamp list, or object
	 *         key => first-seen timestamp pairs.
	 */
	private function read(string $key, string $cacheDir): array
	{
		$cache = Cache::createInstance();
		if ($cache->initCache(self::WINDOW_SECONDS, $key, $cacheDir))
		{
			return (array)$cache->getVars();
		}

		return [];
	}

	/**
	 * @param array $timestamps Window state to store.
	 */
	private function write(string $key, string $cacheDir, array $timestamps): void
	{
		$cache = Cache::createInstance();
		$cache->forceRewriting(true);
		if ($cache->startDataCache(self::WINDOW_SECONDS, $key, $cacheDir))
		{
			$cache->endDataCache($timestamps);
		}
	}
}

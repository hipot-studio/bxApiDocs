<?php

namespace Bitrix\BIConnector\Internal\Services\SqlLab;

use Bitrix\Main\Data\Cache;

/**
 * Remembers a refusal of the limiter, so a caller that keeps calling past a limit is refused without
 * a single query to the master database.
 *
 * The two kinds of refusal are kept apart because they stop being true at different moments. A
 * frequency counter never goes down inside its window, so such a refusal is remembered until the
 * window is over. A concurrency slot is freed as soon as the call holding it ends, so a busy-slots
 * refusal is remembered for the short bucket the limiter asks for and never longer. The moment the
 * entry belongs to is part of its key, so an entry of a window or a bucket that has passed is never
 * read.
 */
class SqlLabRestRefusalCache
{
	public const CACHE_DIR = '/biconnector/sqllab_rest_refusal/';
	public const BUSY_SLOTS_CACHE_DIR = '/biconnector/sqllab_rest_busy_slots/';

	/**
	 * @param array[] $dimensions
	 * @return string|null Type of the dimension whose quota is already spent in this window.
	 */
	public function findRefusal(array $dimensions, int $windowStart, int $windowSeconds): ?string
	{
		return $this->find(self::CACHE_DIR, $dimensions, $windowStart, $windowSeconds);
	}

	public function remember(string $dimensionKey, int $windowStart, int $windowSeconds): void
	{
		$this->store(self::CACHE_DIR, $dimensionKey, $windowStart, $windowSeconds);
	}

	/**
	 * Drops the remembered frequency refusal of a dimension, once its counter went back below the quota
	 * inside the window.
	 */
	public function forget(string $dimensionKey, int $windowStart): void
	{
		Cache::createInstance()->clean($this->cacheKey($dimensionKey, $windowStart), self::CACHE_DIR);
	}

	/**
	 * @param array[] $dimensions
	 * @return string|null Type of the dimension whose concurrency slots were all busy in this bucket.
	 */
	public function findBusySlots(array $dimensions, int $bucketStart, int $bucketSeconds): ?string
	{
		return $this->find(self::BUSY_SLOTS_CACHE_DIR, $dimensions, $bucketStart, $bucketSeconds);
	}

	public function rememberBusySlots(string $dimensionKey, int $bucketStart, int $bucketSeconds): void
	{
		$this->store(self::BUSY_SLOTS_CACHE_DIR, $dimensionKey, $bucketStart, $bucketSeconds);
	}

	/**
	 * @param array[] $dimensions
	 */
	private function find(string $dir, array $dimensions, int $periodStart, int $periodSeconds): ?string
	{
		foreach ($dimensions as $dimension)
		{
			$cache = Cache::createInstance();
			if ($cache->initCache($periodSeconds, $this->cacheKey($dimension['key'], $periodStart), $dir))
			{
				return $dimension['type'];
			}
		}

		return null;
	}

	private function store(string $dir, string $dimensionKey, int $periodStart, int $periodSeconds): void
	{
		$cache = Cache::createInstance();
		$cache->forceRewriting(true);
		if ($cache->startDataCache($periodSeconds, $this->cacheKey($dimensionKey, $periodStart), $dir))
		{
			$cache->endDataCache(true);
		}
	}

	private function cacheKey(string $dimensionKey, int $periodStart): string
	{
		return 'sqllab_rest_refusal_' . $periodStart . '_' . substr(hash('sha256', $dimensionKey), 0, 32);
	}
}

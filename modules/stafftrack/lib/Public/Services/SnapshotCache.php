<?php

namespace Bitrix\StaffTrack\Public\Services;

use Bitrix\Main\Data\Cache;

class SnapshotCache
{
	private const CACHE_TTL = 2592000; // 1 month
	private const CACHE_DIR = '/stafftrack/snapshot';

	public static function getFileId(string $hash): ?int
	{
		$cache = Cache::createInstance();
		$cacheId = 'snap_' . $hash;

		if (!$cache->initCache(self::CACHE_TTL, $cacheId, self::CACHE_DIR))
		{
			return null;
		}

		$fileId = (int)($cache->getVars()['fileId'] ?? 0);

		return $fileId > 0 ? $fileId : null;
	}

	public static function setFileId(string $hash, int $fileId): void
	{
		if ($fileId <= 0)
		{
			return;
		}

		$cache = Cache::createInstance();
		$cacheId = 'snap_' . $hash;

		if ($cache->startDataCache(self::CACHE_TTL, $cacheId, self::CACHE_DIR))
		{
			$cache->endDataCache(['fileId' => $fileId]);
		}
	}
}
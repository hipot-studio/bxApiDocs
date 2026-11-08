<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Internal\Service\Snapshot;

use Bitrix\Main\Data\Cache;

class SnapshotFileIdCache
{
	private const CACHE_TTL = 2592000; // 1 month
	private const FAILURE_TTL = 600;
	private const FAILURE_ID_PREFIX = 'fail_';

	public function __construct(
		private readonly string $cacheDir,
		private readonly string $idPrefix,
	)
	{
	}

	public function getFileId(string $hash): ?int
	{
		$cache = Cache::createInstance();

		if (!$cache->initCache(self::CACHE_TTL, $this->getCacheId($hash), $this->cacheDir))
		{
			return null;
		}

		$fileId = (int)($cache->getVars()['fileId'] ?? 0);

		return $fileId > 0 ? $fileId : null;
	}

	public function setFileId(string $hash, int $fileId): void
	{
		if ($fileId <= 0)
		{
			return;
		}

		$cache = Cache::createInstance();

		if ($cache->startDataCache(self::CACHE_TTL, $this->getCacheId($hash), $this->cacheDir))
		{
			$cache->endDataCache(['fileId' => $fileId]);
		}
	}

	public function hasRecentFailure(string $hash): bool
	{
		$cache = Cache::createInstance();

		return $cache->initCache(self::FAILURE_TTL, $this->getFailureCacheId($hash), $this->cacheDir);
	}

	public function rememberFailure(string $hash): void
	{
		$cache = Cache::createInstance();
		$cacheId = $this->getFailureCacheId($hash);

		$cache->clean($cacheId, $this->cacheDir);
		if ($cache->startDataCache(self::FAILURE_TTL, $cacheId, $this->cacheDir))
		{
			$cache->endDataCache(['failed' => true]);
		}
	}

	private function getFailureCacheId(string $hash): string
	{
		return self::FAILURE_ID_PREFIX . $this->getCacheId($hash);
	}

	private function getCacheId(string $hash): string
	{
		return $this->idPrefix . $hash;
	}
}

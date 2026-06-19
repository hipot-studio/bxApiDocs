<?php

namespace Bitrix\StaffTrack\Public\Services;

use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache;
use Bitrix\StaffTrack\Public\Provider\DepartmentProvider;

class MapDataCache
{
	private const CACHE_TTL = 2592000;
	private const CACHE_DIR = '/stafftrack/map_data';
	private const SHARD_BUCKETS = 100;

	/**
	 * @param int[] $contributorUserIds
	 */
	public static function get(int $currentUserId, string $localDate, array $contributorUserIds): ?array
	{
		if ($currentUserId <= 0)
		{
			return null;
		}

		$cache = Cache::createInstance();
		$cacheId = self::getCacheId($currentUserId, $localDate, $contributorUserIds);
		$cacheDir = self::getCacheDir($currentUserId, $cacheId);

		if (!$cache->initCache(self::CACHE_TTL, $cacheId, $cacheDir))
		{
			return null;
		}

		$data = $cache->getVars();
		$checkIns = is_array($data['checkIns'] ?? null) ? $data['checkIns'] : null;

		return $checkIns;
	}

	/**
	 * @param int[] $contributorUserIds
	 */
	public static function set(int $currentUserId, string $localDate, array $contributorUserIds, array $checkIns): void
	{
		if ($currentUserId <= 0)
		{
			return;
		}

		$cache = Cache::createInstance();
		$cacheId = self::getCacheId($currentUserId, $localDate, $contributorUserIds);
		$cacheDir = self::getCacheDir($currentUserId, $cacheId);

		if (!$cache->startDataCache(self::CACHE_TTL, $cacheId, $cacheDir))
		{
			return;
		}

		$taggedCache = Application::getInstance()->getTaggedCache();
		$taggedCache->startTagCache($cacheDir);
		$taggedCache->registerTag(self::getViewerTag($currentUserId));
		$taggedCache->endTagCache();

		$cache->endDataCache(['checkIns' => $checkIns]);
	}

	public static function invalidateForViewers(array $viewerIds): void
	{
		$taggedCache = Application::getInstance()->getTaggedCache();
		foreach (array_unique(array_map('intval', $viewerIds)) as $viewerId)
		{
			if ($viewerId > 0)
			{
				$taggedCache->clearByTag(self::getViewerTag($viewerId));
			}
		}
	}

	/**
	 * @param int[] $employeeIds
	 */
	public static function invalidateForEmployees(array $employeeIds): void
	{
		$viewerIds = [];
		foreach ($employeeIds as $employeeId)
		{
			$employeeId = (int)$employeeId;
			if ($employeeId <= 0)
			{
				continue;
			}

			$viewerIds[] = $employeeId;
			foreach (DepartmentProvider::getDepartmentHeadsIdsByUserId($employeeId) as $headId)
			{
				$viewerIds[] = $headId;
			}
		}

		if (!empty($viewerIds))
		{
			self::invalidateForViewers($viewerIds);
		}
	}

	/**
	 * @param int[] $contributorUserIds
	 */
	private static function getCacheId(int $currentUserId, string $localDate, array $contributorUserIds): string
	{
		$normalized = array_unique(array_map('intval', $contributorUserIds));
		sort($normalized);

		return 'map_' . $currentUserId . '_' . $localDate . '_' . md5(implode(',', $normalized));
	}

	private static function getCacheDir(int $currentUserId, string $cacheId): string
	{
		return self::CACHE_DIR
			. '/' . ($currentUserId % self::SHARD_BUCKETS)
			. '/' . substr(md5($cacheId), 2, 2)
			. '/' . $cacheId . '/';
	}

	private static function getViewerTag(int $viewerId): string
	{
		return 'stafftrack_map_viewer_' . $viewerId;
	}
}
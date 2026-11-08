<?php

namespace Bitrix\Mobile\Tab\Preset\Service;

use Bitrix\Mobile\Tab\Preset\Dto\ResolvedTabPreset;

final class ActiveTabRequestCache
{
	private static array $resolvedTabs = [];
	private static array $resolvedPresets = [];

	public static function clear(): void
	{
		self::$resolvedTabs = [];
		self::$resolvedPresets = [];
	}

	public static function resolve(
		int $userId,
		string $siteId,
		bool $managedTabPresetsEnabled,
		\Closure $resolver,
	): array
	{
		$cacheKey = self::getCacheKey($userId, $siteId, $managedTabPresetsEnabled);
		if (array_key_exists($cacheKey, self::$resolvedTabs))
		{
			return self::$resolvedTabs[$cacheKey]['tabs'];
		}

		$tabs = $resolver();
		self::$resolvedTabs[$cacheKey] = [
			'userId' => $userId,
			'siteId' => $siteId,
			'tabs' => $tabs,
		];

		return $tabs;
	}

	public static function resolvePreset(
		int $userId,
		string $siteId,
		bool $managedTabPresetsEnabled,
		\Closure $resolver,
	): ResolvedTabPreset
	{
		$cacheKey = self::getCacheKey($userId, $siteId, $managedTabPresetsEnabled);
		if (array_key_exists($cacheKey, self::$resolvedPresets))
		{
			return self::$resolvedPresets[$cacheKey]['preset'];
		}

		$preset = $resolver();
		self::$resolvedPresets[$cacheKey] = [
			'userId' => $userId,
			'siteId' => $siteId,
			'preset' => $preset,
		];

		return $preset;
	}

	public static function clearUser(int $userId): void
	{
		self::clearCacheItems(self::$resolvedTabs, static fn(array $item): bool => $item['userId'] === $userId);
		self::clearCacheItems(self::$resolvedPresets, static fn(array $item): bool => $item['userId'] === $userId);
	}

	public static function clearUsers(array $userIds): void
	{
		foreach ($userIds as $userId)
		{
			self::clearUser((int)$userId);
		}
	}

	public static function clearSite(string $siteId): void
	{
		self::clearCacheItems(self::$resolvedTabs, static fn(array $item): bool => $item['siteId'] === $siteId);
		self::clearCacheItems(self::$resolvedPresets, static fn(array $item): bool => $item['siteId'] === $siteId);
	}

	public static function clearSites(array $siteIds): void
	{
		foreach ($siteIds as $siteId)
		{
			self::clearSite((string)$siteId);
		}
	}

	private static function getCacheKey(int $userId, string $siteId, bool $managedTabPresetsEnabled): string
	{
		return $userId . ':' . strlen($siteId) . ':' . $siteId . ':' . (int)$managedTabPresetsEnabled;
	}

	private static function clearCacheItems(array &$cache, \Closure $shouldClear): void
	{
		foreach ($cache as $cacheKey => $cacheItem)
		{
			if ($shouldClear($cacheItem))
			{
				unset($cache[$cacheKey]);
			}
		}
	}
}

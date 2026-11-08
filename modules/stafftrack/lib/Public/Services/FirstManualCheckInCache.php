<?php

namespace Bitrix\StaffTrack\Public\Services;

use Bitrix\Main\Data\Cache;

class FirstManualCheckInCache
{
	private const CACHE_TTL = 86400;
	private const CACHE_DIR = '/stafftrack/first_manual_check_in';

	public static function get(int $userId, string $localDate): ?bool
	{
		if ($userId <= 0)
		{
			return null;
		}

		$cache = Cache::createInstance();
		if (!$cache->initCache(self::CACHE_TTL, self::getCacheId($userId, $localDate), self::getCacheDir($userId)))
		{
			return null;
		}

		$vars = $cache->getVars();
		if (!is_array($vars) || !array_key_exists('isFirst', $vars))
		{
			return null;
		}

		return (bool)$vars['isFirst'];
	}

	public static function set(int $userId, string $localDate, bool $isFirst): void
	{
		if ($userId <= 0)
		{
			return;
		}

		$cache = Cache::createInstance();
		if ($cache->startDataCache(self::CACHE_TTL, self::getCacheId($userId, $localDate), self::getCacheDir($userId)))
		{
			$cache->endDataCache(['isFirst' => $isFirst]);
		}
	}

	public static function invalidate(int $userId): void
	{
		if ($userId <= 0)
		{
			return;
		}

		Cache::createInstance()->cleanDir(self::getCacheDir($userId));
	}

	private static function getCacheId(int $userId, string $localDate): string
	{
		return 'first_manual_check_in_' . $userId . '_' . $localDate;
	}

	private static function getCacheDir(int $userId): string
	{
		return self::CACHE_DIR . '/' . $userId;
	}
}

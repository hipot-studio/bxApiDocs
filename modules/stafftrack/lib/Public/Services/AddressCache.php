<?php

namespace Bitrix\StaffTrack\Public\Services;

use Bitrix\Main\Data\Cache;
use Bitrix\StaffTrack\Internal\Repository\AddressRepository;

class AddressCache
{
	private const CACHE_TTL = 259200;
	private const CACHE_DIR = '/stafftrack/address/';

	public static function get(string $geohash): ?string
	{
		$cacheId = self::getCacheId($geohash);
		$cache = Cache::createInstance();

		if ($cache->initCache(self::CACHE_TTL, $cacheId, self::CACHE_DIR))
		{
			$data = $cache->getVars();

			return $data['address'] ?? null;
		}

		$row = AddressRepository::findResolvedByGeohash($geohash);

		if ($row === null || ($row['ADDRESS'] ?? '') === '')
		{
			return null;
		}

		AddressRepository::touchLastUsed($geohash);

		if ($cache->startDataCache(self::CACHE_TTL, $cacheId, self::CACHE_DIR))
		{
			$cache->endDataCache(['address' => $row['ADDRESS']]);
		}

		return $row['ADDRESS'];
	}

	public static function set(string $geohash, string $address): void
	{
		$cacheId = self::getCacheId($geohash);
		$cache = Cache::createInstance();

		if ($cache->startDataCache(self::CACHE_TTL, $cacheId, self::CACHE_DIR))
		{
			$cache->endDataCache(['address' => $address]);
		}
	}

	private static function getCacheId(string $geohash): string
	{
		return 'addr_' . $geohash;
	}
}

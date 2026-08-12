<?php

namespace Bitrix\StaffTrack\Public\Services;

use Bitrix\Main\Data\Cache;
use Bitrix\Main\ORM\Fields\CryptoField;
use Bitrix\Main\Security\Cipher;
use Bitrix\Main\Security\SecurityException;
use Bitrix\StaffTrack\Public\Provider\DepartmentProvider;

class MapDataCache
{
	private const CACHE_TTL = 2592000;
	private const CACHE_DIR = '/stafftrack/map_data';
	private const ENC_PREFIX = 'enc:';

	/**
	 * @param int[] $contributorUserIds
	 * @return array{checkIns: array<int, array>, stats: array<int, int>}|null
	 */
	public static function get(int $currentUserId, string $localDate, array $contributorUserIds): ?array
	{
		if ($currentUserId <= 0)
		{
			return null;
		}

		$cache = Cache::createInstance();
		$cacheId = self::getCacheId($currentUserId, $localDate, $contributorUserIds);
		$cacheDir = self::getCacheDir($currentUserId);

		if (!$cache->initCache(self::CACHE_TTL, $cacheId, $cacheDir))
		{
			return null;
		}

		$vars = $cache->getVars();
		$payload = self::decodePayload(is_array($vars) ? ($vars['payload'] ?? null) : null);

		if ($payload === null || !isset($payload['checkIns']) || !is_array($payload['checkIns']))
		{
			return null;
		}

		if (!isset($payload['stats']) || !is_array($payload['stats']))
		{
			return null;
		}

		return [
			'checkIns' => $payload['checkIns'],
			'stats' => $payload['stats'],
		];
	}

	/**
	 * @param int[] $contributorUserIds
	 * @param array{checkIns: array<int, array>, stats: array<int, int>} $data
	 */
	public static function set(int $currentUserId, string $localDate, array $contributorUserIds, array $data): void
	{
		if ($currentUserId <= 0)
		{
			return;
		}

		$cache = Cache::createInstance();
		$cacheId = self::getCacheId($currentUserId, $localDate, $contributorUserIds);
		$cacheDir = self::getCacheDir($currentUserId);

		if (!$cache->startDataCache(self::CACHE_TTL, $cacheId, $cacheDir))
		{
			return;
		}

		$encoded = self::encodePayload($data);
		if ($encoded === null)
		{
			$cache->abortDataCache();

			return;
		}

		$cache->endDataCache(['payload' => $encoded]);
	}

	public static function invalidateForViewers(array $viewerIds): void
	{
		$cache = Cache::createInstance();
		foreach (array_unique(array_map('intval', $viewerIds)) as $viewerId)
		{
			if ($viewerId > 0)
			{
				$cache->cleanDir(self::getCacheDir($viewerId));
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

	private static function getCacheDir(int $viewerId): string
	{
		return self::CACHE_DIR . '/' . $viewerId;
	}

	/**
	 * @param array{checkIns: array<int, array>, stats: array<int, int>} $data
	 */
	private static function encodePayload(array $data): ?string
	{
		$json = json_encode($data);
		if ($json === false)
		{
			return null;
		}

		if (!CryptoField::cryptoAvailable())
		{
			return $json;
		}

		try
		{
			$cipher = new Cipher();
			$encrypted = $cipher->encrypt($json, (string)CryptoField::getDefaultKey());
		}
		catch (SecurityException)
		{
			return null;
		}

		return self::ENC_PREFIX . base64_encode($encrypted);
	}

	private static function decodePayload($stored): ?array
	{
		if (!is_string($stored) || $stored === '')
		{
			return null;
		}

		if (str_starts_with($stored, self::ENC_PREFIX))
		{
			$raw = base64_decode(substr($stored, strlen(self::ENC_PREFIX)), true);
			if ($raw === false)
			{
				return null;
			}

			try
			{
				$cipher = new Cipher();
				$stored = $cipher->decrypt($raw, (string)CryptoField::getDefaultKey());
			}
			catch (SecurityException)
			{
				return null;
			}
		}

		$data = json_decode($stored, true);

		return is_array($data) ? $data : null;
	}
}
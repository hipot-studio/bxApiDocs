<?php

namespace Bitrix\StaffTrack\Public\Services;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\ORM\Fields\CryptoField;
use Bitrix\Main\Security\Cipher;
use Bitrix\Main\Security\SecurityException;
use Bitrix\StaffTrack\Feature;
use Bitrix\StaffTrack\Internal\Integration\HumanResources\IdNormalizer;
use Bitrix\StaffTrack\Public\Provider\DepartmentProvider;

class MapDataCache
{
	private const CACHE_TTL = 2592000;
	private const CACHE_DIR = '/stafftrack/map_data';
	private const CACHE_VERSION = 'v2';
	private const CACHE_TAG = 'stafftrack_map_data';
	private const OPTION_MODULE = 'stafftrack';
	private const GENERATION_OPTION = 'map_data_cache_generation';
	private const ENC_PREFIX = 'enc:';

	/**
	 * @param int[] $contributorUserIds
	 * @return array{checkIns: array<int, array>, stats: array<int, int>, manualStats: array<int, int>, checkInRolloutEnabled: array<int, bool>}|null
	 */
	public static function get(int $currentUserId, string $localDate, array $contributorUserIds): ?array
	{
		if ($currentUserId <= 0)
		{
			return null;
		}

		$cache = Cache::createInstance();
		$cacheId = self::getCacheId($currentUserId, $localDate, $contributorUserIds);
		$cacheDir = self::getCacheDir($currentUserId, self::normalizeLocalDate($localDate));

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

		if (!isset($payload['manualStats']) || !is_array($payload['manualStats']))
		{
			return null;
		}

		if (!isset($payload['checkInRolloutEnabled']) || !is_array($payload['checkInRolloutEnabled']))
		{
			return null;
		}

		return [
			'checkIns' => $payload['checkIns'],
			'stats' => $payload['stats'],
			'manualStats' => $payload['manualStats'],
			'checkInRolloutEnabled' => $payload['checkInRolloutEnabled'],
		];
	}

	/**
	 * @param int[] $contributorUserIds
	 * @param array{checkIns: array<int, array>, stats: array<int, int>, manualStats: array<int, int>, checkInRolloutEnabled: array<int, bool>} $data
	 */
	public static function set(int $currentUserId, string $localDate, array $contributorUserIds, array $data): void
	{
		if ($currentUserId <= 0 || !Feature::isGeoEncryptionAvailable())
		{
			return;
		}

		$cache = Cache::createInstance();
		$cacheId = self::getCacheId($currentUserId, $localDate, $contributorUserIds);
		$cacheDir = self::getCacheDir($currentUserId, self::normalizeLocalDate($localDate));

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

		$taggedCache = Application::getInstance()->getTaggedCache();
		$taggedCache->startTagCache($cacheDir);
		$taggedCache->registerTag(self::getViewerTag($currentUserId));
		$taggedCache->endTagCache();

		$cache->endDataCache(['payload' => $encoded]);
	}

	/**
	 * Bumping the generation instead of walking the directories: the key of every entry carries it, so the
	 * whole cache becomes unreachable in one write, whatever the cache engine is. Walking would cost one
	 * cleanDir per viewer and day - thousands of them by the time this is called - and this runs on the
	 * agent that wipes geo data and in the requests that switch check-in settings.
	 */
	public static function invalidateAll(): void
	{
		Option::set(self::OPTION_MODULE, self::GENERATION_OPTION, (string)(self::getGeneration() + 1));

		Cache::createInstance()->cleanDir(self::CACHE_DIR);
	}

	/**
	 * @param int[] $viewerIds
	 * @param string[] $localDates
	 */
	public static function invalidateForViewers(array $viewerIds, array $localDates = []): void
	{
		$localDates = self::normalizeLocalDates($localDates);

		$cache = Cache::createInstance();
		foreach (array_unique(array_map('intval', $viewerIds)) as $viewerId)
		{
			if ($viewerId <= 0)
			{
				continue;
			}

			if (empty($localDates))
			{
				Application::getInstance()->getTaggedCache()->clearByTag(self::getViewerTag($viewerId));
				$cache->cleanDir(self::getCacheDir($viewerId));

				continue;
			}

			foreach ($localDates as $localDate)
			{
				$cache->cleanDir(self::getCacheDir($viewerId, $localDate));
			}
		}
	}

	/**
	 * @param int[] $employeeIds
	 * @param string[] $localDates
	 */
	public static function invalidateForEmployees(array $employeeIds, array $localDates = []): void
	{
		$employeeIds = IdNormalizer::normalize($employeeIds);
		if (empty($employeeIds))
		{
			return;
		}

		$viewerIds = [...$employeeIds, ...DepartmentProvider::getBranchManagerIdsForEmployees($employeeIds)];

		self::invalidateForViewers($viewerIds, $localDates);
	}

	/**
	 * Keeps the days with the employee they came from: a viewer only ever sees a day of an employee they
	 * watch, so merging the authors and the days of a batch apart would drop a directory of every viewer
	 * for every day of the batch - and most of those pairs never existed.
	 *
	 * @param array<int, string[]> $localDatesByEmployee
	 */
	public static function invalidateForEmployeeDates(array $localDatesByEmployee): void
	{
		$employeeIds = IdNormalizer::normalize(array_keys($localDatesByEmployee));
		if (empty($employeeIds))
		{
			return;
		}

		$managerIdsByEmployee = DepartmentProvider::getBranchManagerIdsByEmployee($employeeIds);

		$datesByViewer = [];
		$viewerIdsWithoutDates = [];
		foreach ($employeeIds as $employeeId)
		{
			$localDates = self::normalizeLocalDates($localDatesByEmployee[$employeeId] ?? []);
			$viewerIds = [$employeeId, ...($managerIdsByEmployee[$employeeId] ?? [])];

			if (empty($localDates))
			{
				array_push($viewerIdsWithoutDates, ...$viewerIds);

				continue;
			}

			foreach ($viewerIds as $viewerId)
			{
				foreach ($localDates as $localDate)
				{
					$datesByViewer[$viewerId][$localDate] = true;
				}
			}
		}

		self::invalidateForViewers($viewerIdsWithoutDates);

		$cache = Cache::createInstance();
		foreach ($datesByViewer as $viewerId => $localDates)
		{
			if ($viewerId <= 0)
			{
				continue;
			}

			foreach (array_keys($localDates) as $localDate)
			{
				$cache->cleanDir(self::getCacheDir($viewerId, $localDate));
			}
		}
	}

	/**
	 * @param string[] $localDates
	 * @return string[]
	 */
	private static function normalizeLocalDates(array $localDates): array
	{
		$normalized = [];
		foreach ($localDates as $localDate)
		{
			$normalizedDate = is_string($localDate) ? self::normalizeLocalDate($localDate) : '';
			if ($normalizedDate !== '')
			{
				$normalized[$normalizedDate] = true;
			}
		}

		return array_keys($normalized);
	}

	private static function normalizeLocalDate(string $localDate): string
	{
		return preg_match('/^\d{4}-\d{2}-\d{2}$/', $localDate) ? $localDate : '';
	}

	/**
	 * @param int[] $contributorUserIds
	 */
	private static function getCacheId(int $currentUserId, string $localDate, array $contributorUserIds): string
	{
		$normalized = array_unique(array_map('intval', $contributorUserIds));
		sort($normalized);

		return 'map_' . self::CACHE_VERSION . '_' . self::getGeneration()
			. '_' . $currentUserId . '_' . $localDate . '_' . md5(implode(',', $normalized));
	}

	private static function getGeneration(): int
	{
		return (int)Option::get(self::OPTION_MODULE, self::GENERATION_OPTION, '1');
	}

	private static function getViewerTag(int $viewerId): string
	{
		return self::CACHE_TAG . '_' . $viewerId;
	}

	private static function getCacheDir(int $viewerId, string $localDate = ''): string
	{
		$dir = self::CACHE_DIR . '/' . $viewerId;

		return $localDate === '' ? $dir : $dir . '/' . $localDate;
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
<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Public\Provider;

use Bitrix\Main\Data\Cache;
use Bitrix\StaffTrack\Internal\Repository\OfficeRepository;

class OfficesCountProvider
{
	private const CACHE_TTL = 60;
	private const CACHE_DIR = '/stafftrack/offices_count';
	private const CACHE_ID = 'offices_count';

	protected OfficeRepository $officeRepository;

	public function __construct()
	{
		$this->officeRepository = new OfficeRepository();
	}

	public function getCount(): int
	{
		$cache = Cache::createInstance();

		if ($cache->initCache(self::CACHE_TTL, self::CACHE_ID, self::CACHE_DIR))
		{
			return (int)($cache->getVars()['count'] ?? 0);
		}

		$count = $this->officeRepository->getCount();

		if ($cache->startDataCache(self::CACHE_TTL, self::CACHE_ID, self::CACHE_DIR))
		{
			$cache->endDataCache(['count' => $count]);
		}

		return $count;
	}

	public static function clearCache(): void
	{
		Cache::createInstance()->clean(self::CACHE_ID, self::CACHE_DIR);
	}
}

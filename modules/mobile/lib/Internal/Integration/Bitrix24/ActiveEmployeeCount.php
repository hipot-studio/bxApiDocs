<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Integration\Bitrix24;

use Bitrix\Bitrix24\License\User;
use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Loader;

/**
 * Integration layer for the licensed employee count of the bitrix24 domain.
 * All other classes in the mobile module must use this class instead of calling
 * Bitrix24\License\User directly.
 */
class ActiveEmployeeCount
{
	private const CACHE_DIR = '/mobile/active_employee_count/';
	private const CACHE_KEY = 'active_employee_count';

	/** Cleared by CUser on every add, update and delete of a real portal user. */
	private const USER_LIST_TAG = 'USER_CARD';

	/**
	 * Number of employees the license counts. Zero is the neutral answer: no bitrix24 module,
	 * no counter behind it or any failure inside it must cost the caller this one number, not
	 * the whole answer it is collected into.
	 *
	 * The number costs a COUNT over the whole user table, so it is kept out of the hot path by
	 * a cache that the portal user list drops as soon as it changes. A failed count is never
	 * cached: a momentary one would otherwise freeze the neutral answer for the whole ttl.
	 */
	public function get(): int
	{
		try
		{
			return $this->getCount();
		}
		catch (\Throwable)
		{
			return 0;
		}
	}

	private function getCount(): int
	{
		if (!$this->includeBitrix24())
		{
			return 0;
		}

		$cached = $this->readCachedCount();
		if ($cached !== null)
		{
			return $cached;
		}

		$count = $this->fetchCount();
		$this->writeCachedCount($count);

		return $count;
	}

	protected function includeBitrix24(): bool
	{
		return Loader::includeModule('bitrix24');
	}

	protected function fetchCount(): int
	{
		return User::getInstance()->getCount();
	}

	protected function readCachedCount(): ?int
	{
		$cache = Cache::createInstance();

		return $cache->initCache($this->getCacheTtl(), self::CACHE_KEY, self::CACHE_DIR)
			? (int)$cache->getVars()
			: null;
	}

	protected function writeCachedCount(int $count): void
	{
		$taggedCache = Application::getInstance()->getTaggedCache();
		$taggedCache->startTagCache(self::CACHE_DIR);
		$taggedCache->registerTag(self::USER_LIST_TAG);
		$taggedCache->endTagCache();

		$cache = Cache::createInstance();
		if ($cache->startDataCache($this->getCacheTtl(), self::CACHE_KEY, self::CACHE_DIR))
		{
			$cache->endDataCache($count);
		}
	}

	/**
	 * Without managed cache the tag is never cleared, so there the number leans on the ttl alone.
	 */
	private function getCacheTtl(): int
	{
		return defined('BX_COMP_MANAGED_CACHE') ? 86400 : 600;
	}
}

<?php

namespace Bitrix\Rest\V3;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Configuration;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Rest\V3\Schema\ModuleConfig;
use Bitrix\Rest\V3\Schema\ModuleManager;

final class CacheManager
{
	private const CACHE_DIR = 'rest/v3.0.4'; // cache directory

	private const CACHE_TTL = 31536000; // One year TTL

	public const ONE_HOUR_TTL = 3600; // One hour TTL

	public const ONE_DAY_TTL = 86400; // One day TTL

	private static ?bool $isCacheDisabled = null;

	private static ?string $globalCacheKeyPart = null;

	public static function get(string $key): mixed
	{
		if (self::isCacheDisabled())
		{
			return null;
		}
		$cacheKey = self::getCacheKey($key);
		$cache = Application::getInstance()->getManagedCache();
		if ($cache->read(self::CACHE_TTL, $cacheKey, self::CACHE_DIR))
		{
			return $cache->get($cacheKey);
		}

		return null;
	}

	public static function set(string $key, mixed $value, int $ttl = self::CACHE_TTL): bool
	{
		if (self::isCacheDisabled())
		{
			return true;
		}
		$cacheKey = self::getCacheKey($key);
		$cache = Application::getInstance()->getManagedCache();
		$cache->read($ttl, $cacheKey, self::CACHE_DIR);
		$cache->setImmediate($cacheKey, $value);

		return true;
	}

	private static function getCacheKey(string $key): string
	{
		if (self::$globalCacheKeyPart === null)
		{
			$modulesConfig = ServiceLocator::getInstance()->get(ModuleManager::class)->getConfigs();
			$keyParts = [];
			/** @var ModuleConfig $moduleConfig */
			foreach ($modulesConfig as $moduleConfig)
			{
				$keyParts[] = join('_', [$moduleConfig->id, $moduleConfig->version, $moduleConfig->modificationDateTime?->format('YmdHis')]);
			}
			self::$globalCacheKeyPart = md5(join('|', $keyParts));
		}

		return self::$globalCacheKeyPart . $key;
	}

	private static function isCacheDisabled(): bool
	{
		if (self::$isCacheDisabled === null)
		{
			$restConfiguration = Configuration::getValue('rest');
			self::$isCacheDisabled = isset($restConfiguration['v3_cache_disabled']) && $restConfiguration['v3_cache_disabled'] === true;
		}

		return self::$isCacheDisabled;
	}

	public static function cleanAll(): void
	{
		$cache = Application::getInstance()->getManagedCache();
		$cache->cleanDir(self::CACHE_DIR);
	}
}

<?php

namespace Bitrix\Rest\V3;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Configuration;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Rest\V3\Schema\ModuleConfig;
use Bitrix\Rest\V3\Schema\ModuleManager;

final class CacheManager
{
	private const CACHE_DIR = 'rest/v3.0.8'; // cache directory

	private const CACHE_TTL = 31536000; // One year TTL

	public const ONE_HOUR_TTL = 3600; // One hour TTL

	public const ONE_DAY_TTL = 86400; // One day TTL

	private static ?bool $isCacheDisabled = null;

	private static ?string $globalCacheKeyPart = null;

	public static function get(string $key, int $ttl = self::CACHE_TTL): mixed
	{
		if (self::isCacheDisabled())
		{
			return null;
		}
		$cacheKey = self::getCacheKey($key);
		$cache = Application::getInstance()->getManagedCache();
		if ($cache->read($ttl, $cacheKey, self::CACHE_DIR))
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

	public static function delete(string $key): void
	{
		if (self::isCacheDisabled())
		{
			return;
		}

		$cache = Application::getInstance()->getManagedCache();
		$cache->clean(self::getCacheKey($key), self::CACHE_DIR);
	}

	private static function getCacheKey(string $key): string
	{
		if (self::$globalCacheKeyPart === null)
		{
			$modulesConfig = ServiceLocator::getInstance()->get(ModuleManager::class)->getConfigs();
			ksort($modulesConfig);

			$signature = array_map(
				static fn(ModuleConfig $moduleConfig): array => $moduleConfig->toArray(),
				$modulesConfig,
			);

			self::$globalCacheKeyPart = md5((string)json_encode($signature));
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

	public static function getWithDir(string $key, string $initDir): mixed
	{
		if (self::isCacheDisabled())
		{
			return null;
		}

		$cacheKey = self::getCacheKey($key);
		$cache = Application::getInstance()->getManagedCache();
		if ($cache->read(self::CACHE_TTL, $cacheKey, $initDir))
		{
			return $cache->get($cacheKey);
		}

		return null;
	}

	public static function setWithDir(string $key, mixed $value, string $initDir, int $ttl = self::CACHE_TTL): bool
	{
		if (self::isCacheDisabled())
		{
			return true;
		}

		$cacheKey = self::getCacheKey($key);
		$cache = Application::getInstance()->getManagedCache();
		$cache->read($ttl, $cacheKey, $initDir);
		$cache->setImmediate($cacheKey, $value);

		return true;
	}

	public static function deleteWithDir(string $key, string $initDir): void
	{
		if (self::isCacheDisabled())
		{
			return;
		}

		$cache = Application::getInstance()->getManagedCache();
		$cache->clean(self::getCacheKey($key), $initDir);
	}

	public static function cleanDirectory(string $initDir): void
	{
		$cache = Application::getInstance()->getManagedCache();
		$cache->cleanDir($initDir);
	}

	public static function cleanAll(): void
	{
		$cache = Application::getInstance()->getManagedCache();
		$cache->cleanDir(self::CACHE_DIR);
	}
}

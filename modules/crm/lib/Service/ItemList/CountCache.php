<?php

namespace Bitrix\Crm\Service\ItemList;

use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache;
use Closure;
use Throwable;

final class CountCache
{
	public const TTL = 5;

	private const KEY_PREFIX = 'ilc_cnt';
	private const LOCK_KEY_PREFIX = 'ilc_cnt_lock_';
	private const LOCK_TIMEOUT = 1;
	private const DIRECTORY_PREFIX = '/crm/item_list_count/';
	private const READ_STATUS_HIT = 'hit';
	private const READ_STATUS_MISS = 'miss';
	private const READ_STATUS_FAILURE = 'failure';
	private const UNSUPPORTED_CACHE_ENGINES = [
		'cacheenginefiles',
		'cacheenginenone',
	];

	private Closure $cacheFactory;
	private Closure $lockAcquirer;
	private Closure $lockReleaser;
	private Closure $cacheEngineTypeResolver;

	public function __construct(
		?callable $cacheFactory = null,
		?callable $lockAcquirer = null,
		?callable $lockReleaser = null,
		?callable $cacheEngineTypeResolver = null,
	)
	{
		$this->cacheFactory = Closure::fromCallable(
			$cacheFactory ?? static fn(): Cache => Cache::createInstance(),
		);
		$this->lockAcquirer = Closure::fromCallable(
			$lockAcquirer
				?? static fn(string $key, int $timeout): bool => Application::getConnection()->lock($key, $timeout),
		);
		$this->lockReleaser = Closure::fromCallable(
			$lockReleaser ?? static function (string $key): void {
				Application::getConnection()->unlock($key);
			},
		);
		$this->cacheEngineTypeResolver = Closure::fromCallable(
			$cacheEngineTypeResolver ?? static fn(): string => Cache::getCacheEngineType(),
		);
	}

	public function get(
		int $entityTypeId,
		int $userId,
		array $filter,
	): ?int
	{
		if (!$this->isCacheSupported())
		{
			return null;
		}

		$readResult = $this->read($entityTypeId, $userId, $filter);

		return $readResult['status'] === self::READ_STATUS_HIT ? $readResult['count'] : null;
	}

	public function getOrLoad(
		int $entityTypeId,
		int $userId,
		array $filter,
		callable $loader,
	): int
	{
		if (!$this->isCacheSupported())
		{
			return $loader();
		}

		$readResult = $this->read($entityTypeId, $userId, $filter);
		if ($readResult['status'] === self::READ_STATUS_FAILURE)
		{
			return $loader();
		}
		if ($readResult['status'] === self::READ_STATUS_HIT)
		{
			return $readResult['count'];
		}

		$key = $this->getKey($entityTypeId, $userId, $filter);
		$lockKey = self::LOCK_KEY_PREFIX . md5($key);
		$isLocked = false;

		try
		{
			$isLocked = (bool)($this->lockAcquirer)($lockKey, self::LOCK_TIMEOUT);
		}
		catch (Throwable)
		{
			$readResult = $this->read($entityTypeId, $userId, $filter);

			return $readResult['status'] === self::READ_STATUS_HIT ? $readResult['count'] : $loader();
		}

		if (!$isLocked)
		{
			$readResult = $this->read($entityTypeId, $userId, $filter);

			return $readResult['status'] === self::READ_STATUS_HIT ? $readResult['count'] : $loader();
		}

		try
		{
			$readResult = $this->read($entityTypeId, $userId, $filter);
			if ($readResult['status'] === self::READ_STATUS_HIT)
			{
				return $readResult['count'];
			}
			if ($readResult['status'] === self::READ_STATUS_MISS)
			{
				$count = $loader();
				$this->set($entityTypeId, $userId, $filter, $count);

				return $count;
			}
		}
		finally
		{
			try
			{
				($this->lockReleaser)($lockKey);
			}
			catch (Throwable)
			{
			}
		}

		return $loader();
	}

	public function set(
		int $entityTypeId,
		int $userId,
		array $filter,
		int $count,
	): void
	{
		if (!$this->isCacheSupported())
		{
			return;
		}

		$key = $this->getKey($entityTypeId, $userId, $filter);

		try
		{
			$cache = ($this->cacheFactory)();
			$cache->noOutput();
			$cache->forceRewriting(true);
			if ($cache->startDataCache(self::TTL, $key, $this->getDirectory($entityTypeId, $key)))
			{
				$cache->endDataCache(['count' => $count]);
			}
		}
		catch (Throwable)
		{
		}
	}

	public function getKey(
		int $entityTypeId,
		int $userId,
		array $filter,
	): string
	{
		return implode('|', [
			self::KEY_PREFIX,
			$entityTypeId,
			$userId,
			CanonicalFilter::hash($filter),
		]);
	}

	private function getDirectory(int $entityTypeId, string $key): string
	{
		return self::DIRECTORY_PREFIX . $entityTypeId . '/' . substr(md5($key), 2, 2) . '/';
	}

	private function read(int $entityTypeId, int $userId, array $filter): array
	{
		$key = $this->getKey($entityTypeId, $userId, $filter);

		try
		{
			$cache = ($this->cacheFactory)();
			if (!$cache->initCache(self::TTL, $key, $this->getDirectory($entityTypeId, $key)))
			{
				return ['status' => self::READ_STATUS_MISS, 'count' => null];
			}

			$value = $cache->getVars();
			if (
				!is_array($value)
				|| !array_key_exists('count', $value)
				|| !is_int($value['count'])
			)
			{
				return ['status' => self::READ_STATUS_MISS, 'count' => null];
			}

			return ['status' => self::READ_STATUS_HIT, 'count' => $value['count']];
		}
		catch (Throwable)
		{
			return ['status' => self::READ_STATUS_FAILURE, 'count' => null];
		}
	}

	private function isCacheSupported(): bool
	{
		try
		{
			return !in_array(
				strtolower((string)($this->cacheEngineTypeResolver)()),
				self::UNSUPPORTED_CACHE_ENGINES,
				true,
			);
		}
		catch (Throwable)
		{
			return false;
		}
	}
}

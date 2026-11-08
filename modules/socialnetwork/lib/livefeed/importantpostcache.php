<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Livefeed;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Closure;

final class ImportantPostCache
{
	private const MAX_TTL = 300;
	private const LOCK_CONTENTION_DELAY = 1;
	private const NEXT_PUBLICATION_CACHE_PATH = '/sonet/livefeed/important/next_publication';
	private const IMPORTANT_POSTS_CACHE_TAG = 'blogpost_important_all';

	/** @return array{path: string, ttl: int} */
	public static function resolveComponentCache(
		int $userId,
		int $requestedTtl,
		string $currentPath,
		bool $managedCache,
	): array
	{
		if ($managedCache)
		{
			return ['path' => $currentPath, 'ttl' => $requestedTtl];
		}

		return [
			'path' => self::getPath($userId),
			'ttl' => $requestedTtl > 0 ? min($requestedTtl, self::MAX_TTL) : $requestedTtl,
		];
	}

	public static function getPath(int $userId): string
	{
		if ($userId <= 0)
		{
			throw new \InvalidArgumentException('User ID must be positive.');
		}

		return \CComponentEngine::MakeComponentPath('bitrix:socialnetwork.blog.blog')
			. '/important/' . substr(md5((string)$userId), 0, 2) . '/' . $userId;
	}

	public static function getSharedNextPublicationDelay(
		string $siteId,
		int $timeZoneOffset,
		DateTime $now,
		Closure $resolver,
	): ?int
	{
		$cache = Application::getInstance()->getCache();
		$cacheId = 'next_publication_' . md5($siteId . '|' . $timeZoneOffset);
		$cachedDelay = self::getCachedNextPublicationDelay($cache, $cacheId, $now);
		if ($cachedDelay['found'])
		{
			return $cachedDelay['delay'];
		}

		$lockName = 'sonet_important_next_publication_' . $cacheId;
		$connection = Application::getConnection();
		if (!$connection->lock($lockName, 0))
		{
			$cachedDelay = self::getCachedNextPublicationDelay($cache, $cacheId, $now);

			return $cachedDelay['found'] ? $cachedDelay['delay'] : self::LOCK_CONTENTION_DELAY;
		}

		try
		{
			$cachedDelay = self::getCachedNextPublicationDelay($cache, $cacheId, $now);
			if ($cachedDelay['found'])
			{
				return $cachedDelay['delay'];
			}

			$cache->clean($cacheId, self::NEXT_PUBLICATION_CACHE_PATH);
			if (!$cache->startDataCache(self::MAX_TTL, $cacheId, self::NEXT_PUBLICATION_CACHE_PATH))
			{
				$cachedDelay = self::getCachedNextPublicationDelay($cache, $cacheId, $now);

				return $cachedDelay['found'] ? $cachedDelay['delay'] : self::LOCK_CONTENTION_DELAY;
			}

			$taggedCache = defined('BX_COMP_MANAGED_CACHE')
				? Application::getInstance()->getTaggedCache()
				: null;
			$isTagCacheActive = false;
			try
			{
				if ($taggedCache !== null)
				{
					$taggedCache->startTagCache(self::NEXT_PUBLICATION_CACHE_PATH);
					$isTagCacheActive = true;
					$taggedCache->registerTag(self::IMPORTANT_POSTS_CACHE_TAG);
				}
				$delay = $resolver();
				if ($taggedCache !== null)
				{
					$taggedCache->endTagCache();
					$isTagCacheActive = false;
				}
				$cache->endDataCache([
					'publicationTimestamp' => $delay === null ? null : $now->getTimestamp() + $delay,
				]);

				return $delay;
			}
			catch (\Throwable $exception)
			{
				if ($isTagCacheActive && $taggedCache !== null)
				{
					$taggedCache->abortTagCache();
				}
				$cache->abortDataCache();

				throw $exception;
			}
		}
		finally
		{
			$connection->unlock($lockName);
		}
	}

	public static function getValidUntilTimestamp(
		DateTime $now,
		int $storageTtl,
		?int $nextPublicationDelay = null,
		?\DateTimeZone $timeZone = null,
	): ?int
	{
		if ($storageTtl <= 0)
		{
			return null;
		}

		return $now->getTimestamp() + self::calculateValidityTtl(
			$storageTtl,
			$now,
			$nextPublicationDelay,
			$timeZone,
		);
	}

	private static function calculateValidityTtl(
		int $storageTtl,
		DateTime $now,
		?int $nextPublicationDelay,
		?\DateTimeZone $timeZone,
	): int
	{
		$nowTimestamp = $now->getTimestamp();
		$localNow = (new \DateTimeImmutable('@' . $nowTimestamp))->setTimezone(
			$timeZone ?? $now->getTimeZone(),
		);
		$nextDay = $localNow->modify('tomorrow')->setTime(0, 0);
		$ttl = min($storageTtl, $nextDay->getTimestamp() - $nowTimestamp);
		if ($nextPublicationDelay !== null)
		{
			$ttl = min($ttl, $nextPublicationDelay);
		}

		return $ttl;
	}

	public static function clear(int $userId): void
	{
		Application::getInstance()->getCache()->cleanDir(self::getPath($userId));
	}

	/** @return array{found: bool, delay: ?int} */
	private static function getCachedNextPublicationDelay(
		\Bitrix\Main\Data\Cache $cache,
		string $cacheId,
		DateTime $now,
	): array
	{
		if (!$cache->initCache(self::MAX_TTL, $cacheId, self::NEXT_PUBLICATION_CACHE_PATH))
		{
			return ['found' => false, 'delay' => null];
		}

		$value = $cache->getVars();
		if (!array_key_exists('publicationTimestamp', $value))
		{
			return ['found' => true, 'delay' => null];
		}

		$publicationTimestamp = $value['publicationTimestamp'];
		if ($publicationTimestamp === null)
		{
			return ['found' => true, 'delay' => null];
		}

		$delay = (int)$publicationTimestamp - $now->getTimestamp();

		return $delay > 0
			? ['found' => true, 'delay' => $delay]
			: ['found' => false, 'delay' => null];
	}
}

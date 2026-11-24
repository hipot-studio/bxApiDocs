<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Marketplace\Banner;

use Bitrix\Main\Data\Cache;

class ProjectionCache
{
	private const DIRECTORY = '/intranet/marketplace_banner/projection';
	private const KEY_VERSION = 4;
	private const MAX_TTL = 86400;

	/**
	 * @return array{hit: bool, projection: BannerProjection|null}
	 */
	public function read(string $language, ?int $snapshotCheckedAt = null, ?int $now = null): array
	{
		$cache = Cache::createInstance();
		$key = $this->getKey($language, $snapshotCheckedAt);
		$now ??= time();

		try
		{
			if (!$cache->initCache(self::MAX_TTL, $key, self::DIRECTORY))
			{
				return ['hit' => false, 'projection' => null];
			}

			$value = $cache->getVars();
			if (
				!is_array($value)
				|| !is_int($value['validUntil'] ?? null)
				|| $value['validUntil'] <= $now
			)
			{
				$cache->clean($key, self::DIRECTORY);

				return ['hit' => false, 'projection' => null];
			}

			if (($value['type'] ?? null) === 'no_banner')
			{
				return ['hit' => true, 'projection' => null];
			}

			if (
				($value['type'] ?? null) === 'banner'
				&& is_array($value['projection'] ?? null)
			)
			{
				$projection = BannerProjection::fromArray($value['projection']);
				if ($projection !== null)
				{
					return [
						'hit' => true,
						'projection' => $projection,
					];
				}
			}

			$cache->clean($key, self::DIRECTORY);
		}
		catch (\Throwable)
		{
			return ['hit' => false, 'projection' => null];
		}

		return ['hit' => false, 'projection' => null];
	}

	public function write(
		string $language,
		?BannerProjection $projection,
		\DateTimeImmutable $validUntil,
		\DateTimeImmutable $now,
		?int $snapshotCheckedAt = null,
	): void
	{
		$ttl = min(self::MAX_TTL, $validUntil->getTimestamp() - $now->getTimestamp());
		if ($ttl <= 0)
		{
			return;
		}

		$cache = Cache::createInstance();
		$started = false;

		try
		{
			$started = $cache->startDataCache($ttl, $this->getKey($language, $snapshotCheckedAt), self::DIRECTORY);
			if (!$started)
			{
				return;
			}

			$validUntilTimestamp = $now->getTimestamp() + $ttl;
			$value = $projection === null
				? ['type' => 'no_banner', 'validUntil' => $validUntilTimestamp]
				: [
					'type' => 'banner',
					'projection' => $projection->toArray(),
					'validUntil' => $validUntilTimestamp,
				];
			$cache->endDataCache($value);
		}
		catch (\Throwable)
		{
			if ($started)
			{
				$cache->abortDataCache();
			}
		}
	}

	public function clean(): void
	{
		Cache::createInstance()->cleanDir(self::DIRECTORY);
	}

	private function getKey(string $language, ?int $snapshotCheckedAt): string
	{
		return 'projection.'
			. self::KEY_VERSION
			. '.'
			. ($snapshotCheckedAt ?? 'local')
			. '.'
			. hash('sha256', $language);
	}
}

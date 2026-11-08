<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Service;

use Bitrix\Main\Data\Cache;

class AutomaticModeConfigurationCache
{
	public const TTL = 60;
	private const PATH = '/timeman/automatic_mode_configuration_v2/';

	public function __construct(private readonly ?\Closure $clock = null)
	{
	}

	public function now(): int
	{
		return $this->clock !== null ? ($this->clock)() : time();
	}

	public function read(int $userId): ?array
	{
		try
		{
			$cache = $this->createCache();
			$cache->noOutput();
			if ($cache->initCache(self::TTL, (string)$userId, $this->getPath($userId)))
			{
				$value = $cache->getVars();

				return $this->isFresh($value) ? $value : null;
			}
		}
		catch (\Throwable)
		{
		}

		return null;
	}

	public function write(int $userId, array $value): void
	{
		if (!$this->isFresh($value))
		{
			return;
		}

		try
		{
			$cache = $this->createCache();
			$cache->noOutput();
			$cache->forceRewriting(true);
			if ($cache->startDataCache(self::TTL, (string)$userId, $this->getPath($userId)))
			{
				if ($this->isFresh($value))
				{
					$cache->endDataCache($value);
				}
				else
				{
					$cache->abortDataCache();
				}
			}
		}
		catch (\Throwable)
		{
			if (isset($cache))
			{
				$cache->abortDataCache();
			}
		}
	}

	public function isFresh(mixed $value): bool
	{
		if (!is_array($value) || !is_int($value['expiresAt'] ?? null))
		{
			return false;
		}

		$now = $this->now();
		$configuration = $value['configuration'] ?? null;

		return $value['expiresAt'] > $now
			&& $value['expiresAt'] <= $now + self::TTL
			&& is_array($configuration)
			&& ($configuration['trusted'] ?? null) === true
			&& is_bool($configuration['hasSchedule'] ?? null)
			&& is_bool($configuration['hasFlexibleSchedule'] ?? null)
			&& is_bool($configuration['timeTrackingEnabled'] ?? null)
		;
	}

	public function invalidateUser(int $userId): void
	{
		try
		{
			$this->createCache()->clean((string)$userId, $this->getPath($userId));
		}
		catch (\Throwable)
		{
		}
	}

	public function invalidateAll(): void
	{
		try
		{
			$this->createCache()->cleanDir(self::PATH);
		}
		catch (\Throwable)
		{
		}
	}

	protected function createCache(): Cache
	{
		return Cache::createInstance();
	}

	protected function getPath(int $userId): string
	{
		return self::PATH . substr(md5((string)$userId), 0, 2) . '/' . $userId . '/';
	}
}

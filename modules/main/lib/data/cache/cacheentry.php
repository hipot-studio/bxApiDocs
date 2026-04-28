<?php

/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage main
 * @copyright 2001-2026 Bitrix
 */

namespace Bitrix\Main\Data\Cache;

use Bitrix\Main\Data\Cache;

class CacheEntry
{
	protected Cache $cache;
	protected string $uniqueId;
	protected int $ttl;
	protected string $cachePath;
	protected string $baseDir;
	protected bool $initialized = false;
	protected float $initTime = 0;

	public function __construct(int $ttl, string $uniqueId, string $cachePath, string $baseDir)
	{
		$this->cache = Cache::createInstance();
		$this->cache->noOutput();

		$this->ttl = $ttl;
		$this->uniqueId = $uniqueId;
		$this->cachePath = $cachePath;
		$this->baseDir = $baseDir;
	}

	public function initialize(): static
	{
		$this->initialized = $this->cache->initCache($this->ttl, $this->uniqueId, $this->cachePath, $this->baseDir);
		$this->initTime = hrtime(true);

		return $this;
	}

	public function isInitialized(): bool
	{
		return $this->initialized;
	}

	public function getVars(): mixed
	{
		return $this->cache->getVars();
	}

	public function getCachePath(): string
	{
		return $this->cachePath;
	}

	public function getInitTime(): float
	{
		return $this->initTime;
	}

	public function write($val): void
	{
		$this->cache->forceRewriting(true);
		$this->cache->startDataCache($this->ttl, $this->uniqueId, $this->cachePath, $val, $this->baseDir);
		$this->cache->endDataCache();
	}
}

<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Cache\Engine;

use Bitrix\Im\V2\Cache\CacheableEntity;
use Bitrix\Im\V2\Cache\CacheKey;
use Bitrix\Im\V2\Cache\CacheLevel;
use Bitrix\Im\V2\Cache\CacheResult;

class StaticCacheEngine extends BaseCacheEngine
{
	private array $cache = [];

	final protected function getCacheLevel(): CacheLevel
	{
		return CacheLevel::Static;
	}

	protected function getInternal(CacheKey $key): CacheResult
	{
		if (isset($this->cache[$key->cachePath->id]))
		{
			return CacheResult::hitOrNegativeHit($this->cache[$key->cachePath->id]);
		}

		return CacheResult::miss();
	}

	protected function setInternal(CacheKey $key, CacheableEntity $entity): void
	{
		$this->cache[$key->cachePath->id] = $entity;
	}

	protected function clearInternal(CacheKey $key): void
	{
		if (isset($this->cache[$key->cachePath->id]))
		{
			unset($this->cache[$key->cachePath->id]);
		}
	}

	public function clearByTag(string $tag): bool
	{
		return $this->nextEngine->clearByTag($tag);
	}
}

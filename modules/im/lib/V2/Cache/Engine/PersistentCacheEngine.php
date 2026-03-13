<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Cache\Engine;

use Bitrix\Im\V2\Cache\CacheableEntity;
use Bitrix\Im\V2\Cache\CacheKey;
use Bitrix\Im\V2\Cache\CacheLevel;
use Bitrix\Im\V2\Cache\CacheResult;
use Bitrix\Im\V2\Cache\Mapper\MapperInterface;
use Bitrix\Im\V2\Cache\NullEntity;
use Bitrix\Main\Application;

class PersistentCacheEngine extends BaseCacheEngine
{
	public function __construct(
		protected CacheEngineInterface $nextEngine,
		protected readonly MapperInterface $mapper,
	)
	{
		parent::__construct($nextEngine);
	}

	final protected function getCacheLevel(): CacheLevel
	{
		return CacheLevel::Persistent;
	}

	protected function getInternal(CacheKey $key): CacheResult
	{
		$cache = Application::getInstance()->getCache();
		$object = null;

		if ($cache->initCache($key->ttl, $key->cachePath->id, $key->cachePath->dir))
		{
			$data = $cache->getVars();

			$object = match (true)
			{
				is_array($data) => ($this->mapper)($data),
				$data instanceof NullEntity => $data,
				default => null,
			};
		}

		return isset($object) ? CacheResult::hitOrNegativeHit($object) : CacheResult::miss();
	}

	protected function setInternal(CacheKey $key, CacheableEntity $entity): void
	{
		$cache = Application::getInstance()->getCache();
		$cache->initCache($key->ttl, $key->cachePath->id, $key->cachePath->dir);

		if ($cache->startDataCache())
		{
			$taggedCache = null;

			if (!empty($key->tags))
			{
				$taggedCache = Application::getInstance()->getTaggedCache();
				$taggedCache->startTagCache($key->cachePath->dir);
				foreach ($key->tags as $tag)
				{
					$taggedCache->registerTag($tag);
				}
			}

			$cache->endDataCache($entity->toCacheRepresentation());

			$taggedCache?->endTagCache();
		}
	}

	protected function clearInternal(CacheKey $key): void
	{
		Application::getInstance()->getCache()->clean($key->cachePath->id, $key->cachePath->dir);
	}

	public function clearByTag(string $tag): bool
	{
		Application::getInstance()->getTaggedCache()->clearByTag($tag);

		return $this->nextEngine->clearByTag($tag);
	}
}

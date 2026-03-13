<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Cache;

use Bitrix\Im\V2\Cache\Path\CachePathManager;
use Bitrix\Im\V2\Cache\Command\DataProviderCommand;
use Bitrix\Im\V2\Cache\Engine\CacheEngineInterface;

/**
 * @template T of CacheableEntity
 */
class CacheManager
{
	/**
	 * @param CacheConfig $config
	 * @param CacheEngineInterface $engineChain
	 */
	public function __construct(
		private readonly CacheConfig $config,
		private readonly CacheEngineInterface $engineChain,
		private readonly CachePathManager $pathManager = new CachePathManager(),
	) {}

	public function get(int|string|null $entityId, CacheLevel $cacheLevel): CacheResult
	{
		$key = $this->createKeyById(entityId: $entityId, cacheLevel: $cacheLevel);
		if ($key === null)
		{
			return CacheResult::miss();
		}

		return $this->engineChain->get($key);
	}

	/**
	 * @param int|string|null $entityId The null-parameter is used for singular entity cases.
	 * @param callable(): mixed $dataProvider
	 * @param array $tags
	 * @return CacheResult<T>
	 */
	public function getOrSet(
		int|string|null $entityId,
		callable $dataProvider,
		array $tags = []
	): CacheResult
	{
		$key = $this->createKeyById(entityId: $entityId, cacheLevel: CacheLevel::All, tags: $tags);
		if ($key === null)
		{
			return CacheResult::miss();
		}

		$command = new DataProviderCommand($dataProvider);

		return $this->engineChain->getOrSet($key, $command);
	}

	public function set(CacheableEntity $entity, CacheLevel $cacheLevel = CacheLevel::All, array $tags = []): bool
	{
		$key = $this->createKeyById(entityId: $entity->getCacheEntityId(), cacheLevel: $cacheLevel, tags: $tags);
		if ($key === null)
		{
			return false;
		}

		return $this->engineChain->set($key, $entity);
	}

	public function clear(int|string|null $entityId, CacheLevel $cacheLevel = CacheLevel::All): bool
	{
		$key = $this->createKeyById(entityId: $entityId, cacheLevel: $cacheLevel);
		if ($key === null)
		{
			return false;
		}

		return $this->engineChain->clear($key);
	}

	public function clearByTag(string $tag): bool
	{
		return $this->engineChain->clearByTag($tag);
	}

	private function createKeyById(int|string|null $entityId, CacheLevel $cacheLevel = CacheLevel::All, array $tags = []): ?CacheKey
	{
		$cachePath = $this->pathManager->getCachePath($entityId, $this->config);
		if (!isset($cachePath))
		{
			return null;
		}

		return new CacheKey(
			cachePath: $cachePath,
			entityId: $entityId,
			entityType: $this->config->entityType,
			ttl: $this->config->ttl,
			cacheLevel: $cacheLevel,
			tags: $tags,
		);
	}
}

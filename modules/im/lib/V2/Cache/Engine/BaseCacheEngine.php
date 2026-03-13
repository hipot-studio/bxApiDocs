<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Cache\Engine;

use Bitrix\Im\V2\Cache\CacheableEntity;
use Bitrix\Im\V2\Cache\CacheKey;
use Bitrix\Im\V2\Cache\CacheLevel;
use Bitrix\Im\V2\Cache\CacheResult;
use Bitrix\Im\V2\Cache\Command\DataProviderCommand;

abstract class BaseCacheEngine implements CacheEngineInterface
{
	abstract protected function getCacheLevel(): CacheLevel;
	abstract protected function setInternal(CacheKey $key, CacheableEntity $entity): void;
	abstract protected function getInternal(CacheKey $key): CacheResult;
	abstract protected function clearInternal(CacheKey $key): void;

	public function __construct(protected CacheEngineInterface $nextEngine) {}

	final public function set(CacheKey $key, CacheableEntity $entity): bool
	{
		if ($this->getCacheLevel()->isSubsetOf($key->cacheLevel))
		{
			$this->setInternal($key, $entity);
		}

		return $this->nextEngine->set($key, $entity);
	}

	final public function get(CacheKey $key): CacheResult
	{
		if ($this->getCacheLevel()->isSubsetOf($key->cacheLevel))
		{
			$getResult = $this->getInternal($key);
			if ($getResult->isHit() || $this->getCacheLevel() === $key->cacheLevel)
			{
				return $getResult;
			}
		}

		return $this->nextEngine->get($key);
	}

	final public function getOrSet(CacheKey $key, DataProviderCommand $dataProvider): CacheResult
	{
		$getResult = $this->getInternal($key);
		if ($getResult->isHit() || $getResult->isNegativeHit())
		{
			return $getResult;
		}

		$nextResult = $this->nextEngine->getOrSet($key, $dataProvider);
		if ($nextResult->isMiss())
		{
			return $nextResult;
		}

		$object = $nextResult->object;
		if ($object)
		{
			$this->setInternal($key, $object);
		}

		return CacheResult::hitOrNegativeHit($object);
	}

	final public function clear(CacheKey $key): bool
	{
		if ($this->getCacheLevel()->isSubsetOf($key->cacheLevel))
		{
			$this->clearInternal($key);
		}

		return $this->nextEngine->clear($key);
	}
}

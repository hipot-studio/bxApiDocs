<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Cache\Engine;

use Bitrix\Im\V2\Cache\CacheableEntity;
use Bitrix\Im\V2\Cache\CacheKey;
use Bitrix\Im\V2\Cache\CacheResult;
use Bitrix\Im\V2\Cache\Mapper\MapperInterface;
use Bitrix\Im\V2\Cache\Command\DataProviderCommand;
use Bitrix\Im\V2\Cache\NullEntity;

class DataProviderEngine implements CacheEngineInterface
{
	public function __construct(private readonly MapperInterface $mapper) {}

	public function get(CacheKey $key): CacheResult
	{
		return CacheResult::miss();
	}

	public function getOrSet(CacheKey $key, DataProviderCommand $dataProvider): CacheResult
	{
		$data = ($dataProvider)();
		if (empty($data))
		{
			return CacheResult::negativeHit(new NullEntity($key->entityId));
		}

		$data = $this->mapper->wrapRawData($data);
		$object = ($this->mapper)($data);

		return CacheResult::hit($object);
	}

	public function set(CacheKey $key, CacheableEntity $entity): bool
	{
		return true;
	}

	public function clear(CacheKey $key): bool
	{
		return true;
	}

	public function clearByTag(string $tag): bool
	{
		return true;
	}
}

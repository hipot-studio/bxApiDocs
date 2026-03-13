<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Cache\Engine;

use Bitrix\Im\V2\Cache\CacheableEntity;
use Bitrix\Im\V2\Cache\CacheKey;
use Bitrix\Im\V2\Cache\CacheResult;
use Bitrix\Im\V2\Cache\Command\DataProviderCommand;

interface CacheEngineInterface
{
	public function get(CacheKey $key): CacheResult;

	public function getOrSet(CacheKey $key, DataProviderCommand $dataProvider): CacheResult;

	public function set(CacheKey $key, CacheableEntity $entity): bool;

	public function clear(CacheKey $key): bool;

	public function clearByTag(string $tag): bool;
}

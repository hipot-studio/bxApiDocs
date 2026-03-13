<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Cache\Mapper;

use Bitrix\Im\V2\Cache\CacheableEntity;
use Bitrix\Im\V2\Cache\PrimitiveCacheable;

/**
 * @implements MapperInterface<PrimitiveCacheable>
 */
class PrimitiveMapper implements MapperInterface
{
	/** @return PrimitiveCacheable */
	public function __invoke(array $data): CacheableEntity
	{
		return new PrimitiveCacheable($data['value'] ?? null);
	}

	public function wrapRawData(mixed $rawData): ?array
	{
		return $rawData !== null ? ['value' => $rawData] : null;
	}
}

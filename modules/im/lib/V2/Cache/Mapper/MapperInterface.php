<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Cache\Mapper;

use Bitrix\Im\V2\Cache\CacheableEntity;

/**
 * @template-covariant TEntity of CacheableEntity
 */
interface MapperInterface
{
	/** @return TEntity */
	public function __invoke(array $data): CacheableEntity;

	public function wrapRawData(mixed $rawData): ?array;
}
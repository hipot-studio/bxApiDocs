<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Cache;

/**
 * @template T
 */
class PrimitiveCacheable implements CacheableEntity
{
	/**
	 * @param T $value
	 */
	public function __construct(public readonly mixed $value) {}

	public function toCacheRepresentation(): array
	{
		return ['value' => $this->value];
	}

	public function getCacheEntityId(): int|string|null
	{
		return null;
	}
}

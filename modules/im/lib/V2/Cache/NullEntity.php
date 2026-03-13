<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Cache;

final class NullEntity implements CacheableEntity
{
	public function __construct(private readonly int|string|null $entityId = null) {}

	public function toCacheRepresentation(): self
	{
		return $this;
	}

	public function getCacheEntityId(): int|string|null
	{
		return $this->entityId;
	}
}

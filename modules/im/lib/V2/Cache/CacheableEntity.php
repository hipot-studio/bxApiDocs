<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Cache;

interface CacheableEntity
{
	public function toCacheRepresentation(): array|NullEntity;
	public function getCacheEntityId(): int|string|null;
}

<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Cache;

use Bitrix\Im\V2\Cache\Path\CachePath;

class CacheKey
{
	public function __construct(
		public readonly CachePath $cachePath,
		public readonly int|string|null $entityId,
		public readonly string $entityType,
		public readonly int $ttl,
		public readonly CacheLevel $cacheLevel = CacheLevel::All,
		public readonly array $tags = []
	) {}
}

<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Cache;

class CacheConfig
{
	private const BASE_DIR = 'bx/imc';

	public function __construct(
		public readonly string $entityType,
		public readonly int $ttl,
		public readonly string $domain,
		public readonly string $baseDir = self::BASE_DIR,
		public readonly int $version = 1,
		public readonly int $partitioningLevels = 1,
	) {}
}

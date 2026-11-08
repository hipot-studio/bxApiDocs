<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Placement;

use Bitrix\Main\Data\ManagedCache;

final class HandlerCache
{
	public function __construct(
		private readonly ManagedCache $cache,
		private readonly int $ttl,
		private readonly string $directory,
	)
	{
	}

	public function get(string $cacheId, callable $loader): array
	{
		if ($this->cache->read($this->ttl, $cacheId, $this->directory))
		{
			$value = $this->cache->get($cacheId);

			return is_array($value) ? $value : [];
		}

		$value = $loader();
		$this->cache->set($cacheId, $value);

		return $value;
	}

	public function clear(): void
	{
		$this->cache->cleanDir($this->directory);
	}
}

<?php

namespace Bitrix\Sign\Contract\Util;

/**
 * Keys of the shared root of the cache of the module. Own scopes and dropping a scope as a whole
 * belong to the implementation that owns them, Util\MainCache.
 */
interface Cache
{
	public function get(string $key, mixed $default = null): mixed;

	/**
	 * @throws \Bitrix\Main\ArgumentException
	 */
	public function set(string $key, mixed $data, ?int $ttl = null): static;

	public function delete(string $key): static;

	public function setTtl(int $ttl): static;
}

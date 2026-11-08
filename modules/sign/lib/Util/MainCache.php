<?php

namespace Bitrix\Sign\Util;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Web\Json;
use Bitrix\Sign\Contract;

class MainCache implements Contract\Util\Cache
{
	private Cache $bitrixCache;
	private const CACHE_DIR = 'sign';
	private readonly ?string $scope;
	private readonly string $dir;
	private int $ttl = 3600;

	/**
	 * @param string|null $scope own subdirectory of the cache of the module. The root of the cache is
	 *                           shared by every consumer of the module, so a consumer that resets its
	 *                           keys as a whole holds a scope of its own: see deleteAll().
	 */
	public function __construct(?string $scope = null)
	{
		$this->bitrixCache = Cache::createInstance();

		$normalizedScope = $scope === null ? '' : trim($scope, '/ ');
		$this->scope = $normalizedScope === '' ? null : $normalizedScope;
		$this->dir = $this->scope === null ? self::CACHE_DIR : self::CACHE_DIR . '/' . $this->scope;
	}

	/**
	 * An instance bound to the own scope of a consumer; the ttl of the source is kept. The instance of
	 * the service locator is shared by every consumer, so the scope is taken and never set: a setter
	 * would move the keys of the neighbours as well.
	 */
	public function withScope(string $scope): static
	{
		$scoped = new static($scope);
		$scoped->ttl = $this->ttl;

		return $scoped;
	}

	public function get(string $key, mixed $default = null): mixed
	{
		$this->bitrixCache->forceRewriting(false);
		if (
			$this->bitrixCache->initCache(
				$this->ttl,
				$key,
				$this->dir
			)
		)
		{
			try
			{
				return Json::decode($this->bitrixCache->getVars());
			}
			catch (ArgumentException $e)
			{
				return $default;
			}
		}

		return $default;
	}

	/**
	 * @throws \Bitrix\Main\ArgumentException
	 */
	public function set(string $key, mixed $data, ?int $ttl = null): static
	{
		$this->bitrixCache->forceRewriting(true);
		if (
			$this->bitrixCache->startDataCache(
				$ttl ?? $this->ttl,
				$key,
				$this->dir
			)
		)
		{
			$this->bitrixCache->endDataCache(Json::encode($data));
		}

		return $this;
	}

	public function delete(string $key): static
	{
		$this->bitrixCache->clean($key, $this->dir);

		return $this;
	}

	/**
	 * Drops every key of the own scope at once. The root of the cache of the module is shared by all
	 * of its consumers, the tariff of the license among them, so an instance without a scope has
	 * nothing it may drop as a whole: bind it to a scope of its own through withScope() first.
	 *
	 * @throws ArgumentException when the instance is bound to the root of the cache of the module
	 */
	public function deleteAll(): static
	{
		if ($this->scope === null)
		{
			throw new ArgumentException(
				'The cache of the module is shared by its consumers and cannot be dropped as a whole',
				'scope',
			);
		}

		$this->bitrixCache->cleanDir($this->dir);

		return $this;
	}

	public function setTtl(int $ttl): static
	{
		$this->ttl = $ttl;

		return $this;
	}
}
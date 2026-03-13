<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Cache\Registry;

use Bitrix\Im\V2\Cache\CacheableEntity;
use Bitrix\Im\V2\Cache\CacheConfig;
use Bitrix\Im\V2\Cache\CacheManager;
use Bitrix\Im\V2\Cache\Engine\DataProviderEngine;
use Bitrix\Im\V2\Cache\Engine\PersistentCacheEngine;
use Bitrix\Im\V2\Cache\Engine\StaticCacheEngine;
use Bitrix\Im\V2\Cache\Mapper\MapperInterface;

/**
 * @phpstan-type TEntity
 */
abstract class DomainCacheRegistry
{
	/** @var array<string, CacheConfig> */
	protected array $configs = [];

	/** @var array<string, MapperInterface> */
	protected array $mappers = [];

	/** @var array<string, CacheManager<CacheableEntity>> */
	private array $managers = [];

	abstract protected function load(): void;

	public function __construct()
	{
		$this->load();
	}

	/**
	 * @param string $entityType
	 * @return CacheManager|null
	 */
	protected function getManager(string $entityType): ?CacheManager
	{
		if (isset($this->managers[$entityType]))
		{
			return $this->managers[$entityType];
		}

		$config = $this->configs[$entityType] ?? null;
		$mapper = $this->mappers[$entityType] ?? null;
		if ($config === null || $mapper === null)
		{
			return null;
		}

		$dataProviderEngine = new DataProviderEngine($mapper);
		$persistentEngine = new PersistentCacheEngine($dataProviderEngine, $mapper);
		$staticEngine = new StaticCacheEngine($persistentEngine);

		$managerInstance = new CacheManager($config, $staticEngine);

		$this->managers[$entityType] = $managerInstance;

		return $managerInstance;
	}

	protected function register(CacheConfig $config, MapperInterface $mapper): static
	{
		$this->configs[$config->entityType] = $config;
		$this->mappers[$config->entityType] = $mapper;

		return $this;
	}
}

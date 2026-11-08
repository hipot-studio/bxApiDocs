<?php

namespace Bitrix\Rest\V3\Event;

use Bitrix\Rest\V3\CacheManager;
use Bitrix\Rest\V3\Event\Provider\EventProviderInterface;
use Bitrix\Rest\V3\Schema\ModuleManager;

final class EventRegistry
{
	private const CACHE_KEY = 'event_registry_descriptors';

	/** @var array<string, EventDescriptor> uppercase event name => descriptor */
	private array $descriptors = [];

	private bool $initialized = false;

	private bool $loading = false;

	/**
	 * Returns descriptor by event name or null if not registered.
	 */
	public function get(string $eventName): ?EventDescriptor
	{
		$this->ensureInitialized();

		return $this->descriptors[mb_strtoupper($eventName)] ?? null;
	}

	/**
	 * Returns all registered descriptors.
	 *
	 * @return array<string, EventDescriptor>
	 */
	public function all(): array
	{
		$this->ensureInitialized();

		return $this->descriptors;
	}

	/**
	 * Manually registers a descriptor (useful for tests and runtime plugins).
	 *
	 * When called outside of {@see ensureInitialized()} loading phase, invalidates
	 * the persisted registry cache so the next process rebuilds from live sources.
	 * The current process keeps using its in-memory registry state.
	 */
	public function register(EventDescriptor $descriptor): void
	{
		$this->descriptors[mb_strtoupper($descriptor->name)] = $descriptor;

		if (!$this->loading)
		{
			CacheManager::delete(self::CACHE_KEY);
		}
	}

	private function ensureInitialized(): void
	{
		if ($this->initialized)
		{
			return;
		}

		$cached = CacheManager::get(self::CACHE_KEY);
		if (is_array($cached))
		{
			$this->descriptors = $cached;
			$this->initialized = true;

			return;
		}

		$this->loading = true;
		try
		{
			$this->loadFromModuleConfigs();
		}
		finally
		{
			$this->loading = false;
		}

		ksort($this->descriptors);
		$this->initialized = true;

		CacheManager::set(self::CACHE_KEY, $this->descriptors);
	}

	/**
	 * Iterates all module configs, finds eventProviders entries,
	 * instantiates each provider and registers its descriptors.
	 */
	private function loadFromModuleConfigs(): void
	{
		$moduleManager = new ModuleManager();

		foreach ($moduleManager->getConfigs() as $moduleConfig)
		{
			if (empty($moduleConfig->eventProviders))
			{
				continue;
			}

			foreach ($moduleConfig->eventProviders as $providerClass)
			{
				$this->loadProvider($providerClass);
			}
		}
	}

	/**
	 * Registers all descriptors returned by the given provider instance.
	 */
	private function registerFromProvider(EventProviderInterface $provider): void
	{
		foreach ($provider::getDescriptors() as $descriptor)
		{
			if (!$descriptor instanceof EventDescriptor)
			{
				continue;
			}

			$this->register($descriptor);
		}
	}

	private function loadProvider(string $providerClass): void
	{
		if (!class_exists($providerClass))
		{
			return;
		}

		$provider = new $providerClass();

		if (!($provider instanceof EventProviderInterface))
		{
			return;
		}

		$this->registerFromProvider($provider);
	}
}
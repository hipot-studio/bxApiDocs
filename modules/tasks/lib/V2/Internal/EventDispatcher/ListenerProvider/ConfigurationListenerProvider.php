<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\EventDispatcher\ListenerProvider;

use Bitrix\Tasks\V2\Internal\ConfigurationDelegate;
use Bitrix\Tasks\V2\Internal\DI\Container;

class ConfigurationListenerProvider extends AbstractListenerProvider
{
	private string $configurationKey;
 
	/**
	 * @param array<class-string, class-string[]> $listeners
	 */
	public function __construct(
		protected Container $container,
		private readonly ConfigurationDelegate $configuration,
	)
	{
	}

	public function useConfigurationKey(string $configurationKey): self
	{
		$this->configurationKey = $configurationKey;

		return $this;
	}
	
	/**
	 * @return string[]
	 */
	protected function resolveListeners(object $event): iterable
	{
		$listeners = $this->configuration->get($this->configurationKey);

		return $listeners[$event::class] ?? [];
	}
}

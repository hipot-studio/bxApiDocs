<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\EventDispatcher\ListenerProvider;

use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Psr\EventDispatcher\ListenerProviderInterface;

abstract class AbstractListenerProvider implements ListenerProviderInterface
{
	public function __construct
	(
		protected Container $container,
	) {
	}

	public function getListenersForEvent(object $event): iterable
	{
		$listeners = $this->resolveListeners($event);

		foreach ($this->normalizeListeners($listeners) as $listener)
		{
			yield $this->container->get($listener);
		}
	}

	/**
	 * @return callable[]
	 */
	abstract protected function resolveListeners(object $event): iterable;

	private function normalizeListeners(iterable $listeners): iterable
	{
		return $listeners;
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\EventDispatcher;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Configuration;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;

/**
 * Event router of the socialnetwork module.
 *
 * Faithful-minimal copy of the tasks infrastructure
 * ({@see \Bitrix\Tasks\V2\Internal\EventDispatcher\EventDispatcher}):
 * - public static surface {@see self::dispatch()};
 * - subscriber map keyed by event class ({@see \config/events.php});
 * - asynchronous handling (via a core background job).
 *
 * Entry point: the core calls {@see self::dispatch()} as a legacy-event handler
 * (registerEventHandler ... toMethod 'dispatch'), passing the typed event object
 * as the first argument.
 */
class EventDispatcher
{
	private const CONFIGURATION_KEY = 'events';

	/**
	 * @template T of object
	 * @param T $event
	 * @return T
	 */
	public static function dispatch(object $event): object
	{
		$listeners = static::getListenersForEvent($event);
		if (empty($listeners))
		{
			return $event;
		}

		Application::getInstance()->addBackgroundJob(
			static function () use ($event, $listeners): void {
				static::handle($event, $listeners);
			}
		);

		return $event;
	}

	/**
	 * @param class-string[] $listeners
	 */
	private static function handle(object $event, array $listeners): void
	{
		$container = Container::getInstance();

		foreach ($listeners as $listenerClass)
		{
			$listener = $container->get($listenerClass);

			if (is_callable($listener))
			{
				$listener($event);
			}
		}
	}

	/**
	 * @return class-string[]
	 */
	private static function getListenersForEvent(object $event): array
	{
		$map = Configuration::getInstance('socialnetwork')->get(self::CONFIGURATION_KEY);
		if (!is_array($map))
		{
			return [];
		}

		$listeners = $map[$event::class] ?? [];

		return is_array($listeners) ? $listeners : [];
	}
}

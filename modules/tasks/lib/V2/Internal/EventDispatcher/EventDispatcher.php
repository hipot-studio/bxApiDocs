<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\EventDispatcher;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Tasks\V2\Internal\Async\QueueId;
use Bitrix\Tasks\V2\Psr\EventDispatcher\EventDispatcherInterface;

class EventDispatcher
{
	/**
	 * @template T
	 * @param T $event
	 * @return T
	 */
	public static function dispatch(object $event, ...$args): mixed
	{
		if ($event instanceof Event\DispatchInBackground)
		{
			return static::dispatchInBackground($event, ...$args);
		}

		if ($event instanceof Event\DispatchInQueue)
		{
			return static::dispatchInQueue($event, ...$args);
		}

		return static::resolve(EventDispatcher\EventDispatcher::class)->dispatch($event);
	}

	/**
	 * @template T
	 * @param T $event
	 * @return T
	 */
	public static function dispatchInBackground(object $event, ?int $priority = null): mixed
	{
		return static::resolve(EventDispatcher\BackgroundEventDispatcher::class)->dispatch($event, $priority);
	}

	/**
	 * @template T
	 * @param T $event
	 * @return T
	 */
	public static function dispatchInQueue(object $event, QueueId|string|null $queueId = null): mixed
	{
		return static::resolve(EventDispatcher\QueueEventDispatcher::class)->dispatch($event, $queueId);
	}

	/**
	 * @template T of EventDispatcherInterface
	 * @param class-string<T> $class
	 * @return T
	 */
	private static function resolve(string $class): EventDispatcherInterface
	{
		return ServiceLocator::getInstance()->get($class);
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\EventDispatcher\EventDispatcher;

use Bitrix\Tasks\V2\Internal\EventDispatcher\ListenerProvider\ListenerProvider;
use Bitrix\Tasks\V2\Internal\Logger;
use Bitrix\Tasks\V2\Psr\EventDispatcher\EventDispatcherInterface;
use Bitrix\Tasks\V2\Psr\EventDispatcher\StoppableEventInterface;

class EventDispatcher implements EventDispatcherInterface
{
	public function __construct(
		private readonly ListenerProvider $provider,
		private readonly Logger $logger,
	)
	{
	}

	/**
	 * @template T of object
	 * @param T $event
	 * @return T
	 */
	public function dispatch(object $event)
	{
		foreach ($this->provider->getListenersForEvent($event) as $listener)
		{
			if ($this->shouldStopPropagation($event))
			{
				break;
			}

			$callableName = '';

			if (is_callable($listener, callable_name: $callableName))
			{
				$listener($event);
			}
			else
			{
				$this->logger->logError(sprintf(
					'Unable to invoke event listener %s for event %s since listener is not callable',
					$callableName,
					$event::class,
				), \BadFunctionCallException::class);
			}
		}

		return $event;
	}

	private function shouldStopPropagation(object $event): bool
	{
		return $event instanceof StoppableEventInterface && $event->isPropagationStopped();
	}
}

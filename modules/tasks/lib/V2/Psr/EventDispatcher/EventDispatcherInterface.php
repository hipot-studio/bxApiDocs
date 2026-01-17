<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Psr\EventDispatcher;

/**
 * Defines a dispatcher for events.
 *
 * @see https://www.php-fig.org/psr/psr-14/
 * @see \Psr\EventDispatcher\EventDispatcherInterface
 */
interface EventDispatcherInterface
{
    /**
     * Provide all relevant listeners with an event to process.
     * @template T of object
     * @param T $event
     * 	 The object to process.
     * @return T
     *   The Event that was passed, now modified by listeners.
     */
    public function dispatch(object $event);
}

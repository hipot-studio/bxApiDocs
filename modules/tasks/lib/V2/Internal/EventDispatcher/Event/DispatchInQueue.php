<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\EventDispatcher\Event;

use Bitrix\Tasks\V2\Internal\Async\QueueId;

/**
 * @method QueueId getQueueId() Get the queue name for event dispatching.
 */
interface DispatchInQueue
{
}

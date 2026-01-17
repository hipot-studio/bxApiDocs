<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\EventDispatcher;

use Bitrix\Tasks\V2\Psr\EventDispatcher\StoppableEventInterface;

class Event implements StoppableEventInterface, \ArrayAccess
{
	use Event\StoppableEventTrait;
	use Event\TransientDataTrait;
}

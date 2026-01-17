<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI\Queue;

use Bitrix\Main\Event;

abstract class QueueJob
{
	protected Event $event;

	public function __construct(Event $event)
	{
		$this->event = $event;
	}

	public abstract function processQueueJob(): void;
	public abstract function processFailedJob(): void;
}

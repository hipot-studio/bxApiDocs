<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Event\Task;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\EventDispatcher\ListenBy;
use Bitrix\Tasks\V2\Internal\EventHandler\Task\OnAccessRequested;

#[ListenBy(
	OnAccessRequested\NotifyChat::class,
)]
class OnAccessRequestedEvent
{
	public function __construct(
		public readonly Entity\Task $task,
		public readonly Entity\User $triggeredBy,
	)
	{
	}
}

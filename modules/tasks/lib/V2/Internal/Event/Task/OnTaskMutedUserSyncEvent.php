<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Event\Task;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\EventDispatcher\Event;
use Bitrix\Tasks\V2\Internal\EventDispatcher\ListenBy;
use Bitrix\Tasks\V2\Internal\EventHandler\Task\OnTaskMutedUserSync;

#[ListenBy(
	OnTaskMutedUserSync\ChatSync::class,
)]
class OnTaskMutedUserSyncEvent extends Event
{
	public function __construct(
		public readonly Entity\Task $task,
	)
	{
	}
}

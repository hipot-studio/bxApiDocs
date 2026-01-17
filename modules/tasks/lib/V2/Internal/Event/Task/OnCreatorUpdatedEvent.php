<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Event\Task;

use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\EventDispatcher\Event;
use Bitrix\Tasks\V2\Internal\EventDispatcher\ListenBy;
use Bitrix\Tasks\V2\Internal\EventHandler\Task\OnCreatorUpdated;

#[ListenBy(
	OnCreatorUpdated\SyncChat::class,
)]
class OnCreatorUpdatedEvent extends Event
{
	public function __construct(
		public readonly Task $task,
		public readonly User $newCreator,
		public readonly User $previousCreator,
	) {
	}
}

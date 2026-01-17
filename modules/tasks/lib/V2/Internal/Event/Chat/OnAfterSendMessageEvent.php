<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Event\Chat;

use Bitrix\Im\V2\Message;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\EventDispatcher\Event;
use Bitrix\Tasks\V2\Internal\EventDispatcher\ListenBy;
use Bitrix\Tasks\V2\Internal\EventHandler\Chat\OnAfterSendMessage;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\AbstractNotify;

#[ListenBy(
	OnAfterSendMessage\UpdateLastActivityDate::class,
	OnAfterSendMessage\UpdateCounters::class,
)]
class OnAfterSendMessageEvent extends Event
{
	public function __construct(
		public readonly Task $task,
		public readonly Message $message,
		public readonly AbstractNotify $notification,
	)
	{
	}
}

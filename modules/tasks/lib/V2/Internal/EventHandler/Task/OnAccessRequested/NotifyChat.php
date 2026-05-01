<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\EventHandler\Task\OnAccessRequested;

use Bitrix\Tasks\V2\Internal\Event\Task\OnAccessRequestedEvent;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\NotifyAccessRequested;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSender;

class NotifyChat
{
	public function __construct(
		private readonly MessageSender $sender,
	)
	{
	}

	public function __invoke(OnAccessRequestedEvent $event): void
	{
		$notification = new NotifyAccessRequested(
			triggeredBy: $event->triggeredBy,
		);

		$this->sender->sendMessage($event->task, $notification);
	}
}

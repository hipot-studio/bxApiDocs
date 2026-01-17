<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\EventHandler\Task\OnCrmItemsChanged;

use Bitrix\Tasks\V2\Internal\Event\Task\OnCrmItemsChangedEvent;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\NotifyCrmItemsChanged;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSender;
use Bitrix\Tasks\V2\Internal\Logger;

class NotifyChat
{
	public function __construct(
		private readonly MessageSender $sender,
		private readonly Logger $logger,
	)
	{
	}

	public function __invoke(OnCrmItemsChangedEvent $event): void
	{
		$notification = new NotifyCrmItemsChanged(
			task: $event->task,
			triggeredBy: $event->triggeredBy,
		);

		try
		{
			$this->sender->sendMessage($event->task, $notification);
		}
		catch (\Throwable $e)
		{
			$this->logger->logError($e);
		}
	}
}

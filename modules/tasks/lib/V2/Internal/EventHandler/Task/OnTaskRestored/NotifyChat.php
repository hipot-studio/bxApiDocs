<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\EventHandler\Task\OnTaskRestored;

use Bitrix\Tasks\V2\Internal\Event\Task\OnTaskRestoredEvent;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\NotifyTaskRestored;
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

	public function __invoke(OnTaskRestoredEvent $event): void
	{
		$notification = new NotifyTaskRestored(
			task: $event->task,
			triggeredBy: $event->triggeredBy,
		);

		try
		{
			$this->sender->sendMessage($event->task, $notification, $event->triggeredBy);
		}
		catch (\Throwable $e)
		{
			$this->logger->logError($e);
		}
	}
}

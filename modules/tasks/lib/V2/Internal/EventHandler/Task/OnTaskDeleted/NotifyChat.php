<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\EventHandler\Task\OnTaskDeleted;

use Bitrix\Tasks\V2\Internal\Event\Task\OnTaskDeletedEvent;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\NotifyTaskDeleted;
use Bitrix\Tasks\V2\Internal\Logger;
use Bitrix\Tasks\V2\Public\Service\MessageSender;

class NotifyChat
{
	public function __construct(
		private readonly MessageSender $sender,
		private readonly Logger $logger,
	)
	{
	}

	public function __invoke(OnTaskDeletedEvent $event): void
	{
		$notification = new NotifyTaskDeleted(
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

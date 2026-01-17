<?php

namespace Bitrix\Tasks\V2\Internal\EventHandler\Chat\OnAfterSendMessage;

use Bitrix\Tasks\V2\Internal\Event\Chat\OnAfterSendMessageEvent;
use Bitrix\Tasks\V2\Internal\Logger;
use Bitrix\Tasks\V2\Internal\Service\Counter;

class UpdateCounters
{
	public function __construct
	(
		private readonly Counter\Service $counters,
		private readonly Logger $logger,
	)
	{
	}

	public function __invoke(OnAfterSendMessageEvent $event): void
	{
		try
		{
			$this->counters->send(new Counter\Command\AfterCommentAdd(
				userId: $event->message?->getAuthorId(),
				taskId: (int)$event->task->getId(),
				messageId: (int)$event->message?->getMessageId(),
				groupId: $event->task->group?->getId(),
			));
		}
		catch (\Throwable $e)
		{
			$this->logger->logError($e);
		}
	}
}
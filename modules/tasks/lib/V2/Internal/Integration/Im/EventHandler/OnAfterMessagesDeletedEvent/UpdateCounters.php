<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\EventHandler\OnAfterMessagesDeletedEvent;

use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterDeleteMessagesEvent;
use Bitrix\Tasks\V2\Internal\Logger;
use Bitrix\Tasks\V2\Internal\Service\Counter;

class UpdateCounters
{
	public function __construct
	(
		private readonly Counter\Service $counters,
		private readonly Logger $logger,
	) {
	}

	public function __invoke(AfterDeleteMessagesEvent $event): void
	{
		try
		{
			foreach ($event->getMessages() as $message)
			{
				$this->counters->send(
					new Counter\Command\AfterCommentDelete(
						userId: $message->getAuthorId(),
						taskId: (int) $event->getChat()->getEntityId(),
						messageId: (int) $message->getId(),
					),
				);
			}
		}
		catch (\Throwable $e)
		{
			$this->logger->logError($e);
		}
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\EventHandler\OnAfterReadAllChatsByTypeTasksTask;

use Bitrix\Im\V2\Message\Event\AfterReadAllChatsByTypeEvent;
use Bitrix\Tasks\V2\Internal\Integration\Pull\Push;
use Bitrix\Tasks\V2\Internal\Logger;

class SendPushNotification
{
	public function __construct(
		private readonly Push\Service $push,
		private readonly Logger $logger, 
	) {
	}

	public function __invoke(AfterReadAllChatsByTypeEvent $event): void
	{
		if (!$this->push->isEnabled())
		{
			return;
		}

		try
		{
			$this->push->send($event->getUserId(), new Push\CommentsViewed(userId: $event->getUserId(), groupId: null));
		}
		catch (\Throwable $e)
		{
			$this->logger->logError($e);
		}
	}
}

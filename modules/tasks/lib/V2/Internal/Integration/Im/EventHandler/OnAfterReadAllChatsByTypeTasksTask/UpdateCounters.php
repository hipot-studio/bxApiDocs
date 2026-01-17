<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\EventHandler\OnAfterReadAllChatsByTypeTasksTask;

use Bitrix\Im\V2\Message\Event\AfterReadAllChatsByTypeEvent;
use Bitrix\Tasks\V2\Internal\Logger;
use Bitrix\Tasks\V2\Internal\Service\Counter;

class UpdateCounters
{
	public function __construct(
		private readonly Counter\Service $counters,
		private readonly Logger $logger,
	) {
	}

	public function __invoke(AfterReadAllChatsByTypeEvent $event): void
	{
		try
		{
			$this->counters->send(new Counter\Command\AfterCommentsReadAll(userId: $event->getUserId()));
		}
		catch (\Throwable $e)
		{
			$this->logger->logError($e);
		}
	}
}

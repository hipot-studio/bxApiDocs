<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\EventHandler\OnAfterReadMessageEvent;

use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterReadMessagesEvent;
use Bitrix\Tasks\V2\Internal\Logger;
use Bitrix\Tasks\V2\Internal\Repository\TaskReadRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Counter;

class UpdateCounters
{
	public function __construct
	(
		private readonly TaskReadRepositoryInterface $repository,
		private readonly Counter\Service $counters,
		private readonly Logger $logger,
	) {
	}

	public function __invoke(AfterReadMessagesEvent $event): void
	{
		try
		{
			$task = $this->repository->getById((int)$event->getChat()->getEntityId());
		}
		catch (\Throwable $e)
		{
			$this->logger->logError($e);
			return;
		}

		if (null === $task)
		{
			return;
		}

		try
		{
			$this->counters->send(new Counter\Command\AfterCommentsRead(
				userId: (int) $event->getReaderId(),
				taskId: $task->getId(),
			));
		}
		catch (\Throwable $e)
		{
			$this->logger->logError($e);
		}
	}
}

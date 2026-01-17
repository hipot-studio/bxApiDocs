<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\EventHandler\OnAfterSendMessageEvent;

use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterSendMessageEvent;
use Bitrix\Tasks\V2\Internal\Logger;
use Bitrix\Tasks\V2\Internal\Repository\TaskRepositoryInterface;

class UpdateLastActivityDate
{
	public function __construct(
		private readonly TaskRepositoryInterface $taskWriteRepository,
		private readonly Logger $logger,
	)
	{
	}

	public function __invoke(AfterSendMessageEvent $event): void
	{
		if ($event->getMessage()->getAuthorId() === 0)
		{
			return;
		}

		try
		{
			$this->taskWriteRepository->updateLastActivityDate(
				taskId: (int)$event->getChat()->getEntityId(),
				activityTs: $event->getMessage()->getDateCreate()?->getTimestamp() ?? time(),
			);
		}
		catch (\Throwable $e)
		{
			$this->logger->logError($e);
		}
	}
}

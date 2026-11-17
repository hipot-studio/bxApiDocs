<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\EventHandler\OnAfterReadChatsByTypeBatchTasksTask;

use Bitrix\Im\V2\Message\Event\AfterReadChatsByTypeBatchEvent;
use Bitrix\Tasks\V2\Internal\Logger;
use Bitrix\Tasks\V2\Internal\Repository\ChatRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Counter;

class UpdateCounters
{
	public function __construct(
		private readonly ChatRepositoryInterface $repository,
		private readonly Counter\Service $counters,
		private readonly Logger $logger,
	) {
	}

	public function __invoke(AfterReadChatsByTypeBatchEvent $event): void
	{
		$userId = $event->getUserId();
		$chatIds = $event->getChatIds();

		if (empty($chatIds))
		{
			return;
		}

		foreach (ChatIdsChunker::split($chatIds) as $chunk)
		{
			try
			{
				$taskIds = $this->repository->findTaskIdsByChatIds($chunk);
			}
			catch (\Throwable $e)
			{
				$this->logger->logError($e);
				continue;
			}

			if (empty($taskIds))
			{
				continue;
			}

			try
			{
				$this->counters->send(new Counter\Command\AfterCommentsReadList($userId, $taskIds));
			}
			catch (\Throwable $e)
			{
				$this->logger->logError($e);
			}
		}
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\EventHandler\OnAfterReadChatsByTypeBatchTasksTask;

use Bitrix\Im\V2\Message\Event\AfterReadChatsByTypeBatchEvent;
use Bitrix\Tasks\V2\Internal\Integration\Pull\Push;
use Bitrix\Tasks\V2\Internal\Logger;
use Bitrix\Tasks\V2\Internal\Repository\ChatRepositoryInterface;

class SendPushNotification
{
	public function __construct(
		private readonly ChatRepositoryInterface $repository,
		private readonly Push\Service $push,
		private readonly Logger $logger,
	) {
	}

	public function __invoke(AfterReadChatsByTypeBatchEvent $event): void
	{
		if (!$this->push->isEnabled())
		{
			return;
		}

		try
		{
			$taskIds = $this->getTaskIds($event->getChatIds());
			$this->push->send(
				$event->getUserId(),
				new Push\CommentsViewed(userId: $event->getUserId(), groupId: null, taskIds: $taskIds),
			);
		}
		catch (\Throwable $e)
		{
			$this->logger->logError($e);
		}
	}

	private function getTaskIds(array $chatIds): array
	{
		if (empty($chatIds))
		{
			return [];
		}

		try
		{
			$taskIds = [];
			foreach (ChatIdsChunker::split($chatIds) as $chunk)
			{
				$taskIds = array_merge($taskIds, $this->repository->findTaskIdsByChatIds($chunk));
			}

			return array_values(array_unique($taskIds));
		}
		catch (\Throwable $e)
		{
			$this->logger->logError($e);

			return [];
		}
	}
}

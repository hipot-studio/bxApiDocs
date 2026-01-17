<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\EventHandler\OnAfterSendMessageEvent;

use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterSendMessageEvent;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Logger;
use Bitrix\Tasks\V2\Internal\Repository\Task\Select;
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

	public function __invoke(AfterSendMessageEvent $event): void
	{
		try
		{
			$task = $this->repository->getById((int)$event->getChat()->getEntityId(), select: new Select(members: true));
		}
		catch (\Throwable $e)
		{
			$this->logger->logError($e);
			return;
		}

		if ($task === null)
		{
			return;
		}

		$this->updateCounters($event, $task);
		$this->updateMentionedUsers($event, $task);
	}

	private function updateCounters(AfterSendMessageEvent $event, Task $task): void
	{
		if ($event->getMessage()->isSystem())
		{
			return;
		}

		try
		{
			$this->counters->send(new Counter\Command\AfterCommentAdd(
				userId: (int)$event->getMessage()->getAuthorId(),
				taskId: (int)$task->getId(),
				messageId: $event->getMessage()->getId(),
				groupId: $task->group?->getId(),
			));
		}
		catch (\Throwable $e)
		{
			$this->logger->logError($e);
		}
	}

	private function updateMentionedUsers(AfterSendMessageEvent $event, Task $task): void
	{
		try
		{
			foreach ($event->getMessage()->getMentionedUserIds() as $userId)
			{
				$this->counters->send(new Counter\Command\AfterUserMentioned(
					taskId: $task->getId(),
					userId: $userId,
					groupId: $task->group?->getId(),
					messageId: $event->getMessage()->getId(),
				));
			}
		}
		catch (\Throwable $e)
		{
			$this->logger->logError($e);
		}
	}
}

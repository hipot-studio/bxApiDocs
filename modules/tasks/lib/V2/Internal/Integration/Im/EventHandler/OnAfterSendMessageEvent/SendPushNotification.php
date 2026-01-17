<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\EventHandler\OnAfterSendMessageEvent;

use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterSendMessageEvent;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Integration\Pull\Push;
use Bitrix\Tasks\V2\Internal\Logger;
use Bitrix\Tasks\V2\Internal\Repository\Task\Select;
use Bitrix\Tasks\V2\Internal\Repository\TaskReadRepositoryInterface;

class SendPushNotification
{
	public function __construct
	(
		private readonly TaskReadRepositoryInterface $repository,
		private readonly Push\Service $push,
		private readonly Logger $logger,
	) {
	}

	public function __invoke(AfterSendMessageEvent $event): void
	{
		if (!$this->push->isEnabled())
		{
			return;
		}

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

		try
		{
			$this->push->send($task->getMemberIds(), $this->getPayload($event, $task));
		}
		catch (\Throwable $e)
		{
			$this->logger->logError($e);
		}
	}

	private function getPayload(AfterSendMessageEvent $event, Task $task): Push\CommentAdded
	{
		return new Push\CommentAdded(
			taskId: $task->getId(),
			ownerId: $event->getMessage()->getAuthorId(),
			messageId: $event->getMessage()->getId(),
			groupId: $task->group?->getId(),
			participants: $task->getMemberIds(),
			pullComment: true,
			isCompleteComment: false,
		);
	}
}

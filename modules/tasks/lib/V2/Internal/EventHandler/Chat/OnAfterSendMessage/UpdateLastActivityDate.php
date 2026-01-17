<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\EventHandler\Chat\OnAfterSendMessage;

use Bitrix\Tasks\V2\Internal\Event\Chat\OnAfterSendMessageEvent;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\AbstractNotify;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\NotifyChatCreatedForExistingTask;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\NotifyTaskHasForumComments;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\NotifyTaskHasLegacyChat;
use Bitrix\Tasks\V2\Internal\Logger;
use Bitrix\Tasks\V2\Internal\Repository\TaskRepositoryInterface;

class UpdateLastActivityDate
{
	private array $blackListNotifications = [
		NotifyChatCreatedForExistingTask::class,
		NotifyTaskHasLegacyChat::class,
		NotifyTaskHasForumComments::class,
	];

	public function __construct(
		private readonly TaskRepositoryInterface $taskWriteRepository,
		private readonly Logger $logger,
	)
	{
	}

	public function __invoke(OnAfterSendMessageEvent $event): void
	{
		if ($this->isBlackListed($event->notification))
		{
			return;
		}

		try
		{
			$this->taskWriteRepository->updateLastActivityDate(
				taskId: $event->task->getId(),
				activityTs: $event->message->getDateCreate()?->getTimestamp() ?? time(),
			);
		}
		catch (\Throwable $e)
		{
			$this->logger->logError($e);
		}
	}

	private function isBlackListed(AbstractNotify $notification): bool
	{
		return in_array($notification::class, $this->blackListNotifications, true);
	}
}

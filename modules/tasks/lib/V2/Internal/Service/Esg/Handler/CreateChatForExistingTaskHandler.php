<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Esg\Handler;

use Bitrix\Tasks\Internals\Log\Logger;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Event\Task\OnTaskMutedUserSyncEvent;
use Bitrix\Tasks\V2\Internal\EventDispatcher\EventDispatcher;
use Bitrix\Tasks\V2\Internal\Integration\Im\Chat;
use Bitrix\Tasks\V2\Internal\Integration\Im\ChatNotificationInterface;
use Bitrix\Tasks\V2\Internal\Integration\Im\NotificationType;
use Bitrix\Tasks\V2\Internal\Repository\ChatRepositoryInterface;
use RuntimeException;

class CreateChatForExistingTaskHandler
{
	public function __construct(
		private readonly Chat $chatIntegration,
		private readonly ChatNotificationInterface $chatNotification,
		private readonly ChatRepositoryInterface $chatRepository,
		private readonly EventDispatcher $eventDispatcher,
	)
	{
	}

	public function handle(Task $task): Task
	{
		// create new chat
		$result = $this->chatIntegration->addChatByTaskId($task->getId());

		$chatId = $result->getId();
		if (!$result->isSuccess() || $chatId <= 0)
		{
			Logger::logErrors($result->getErrorCollection());
			throw new RuntimeException('There was an error while creating new task chat');
		}

		$this->chatRepository->save($chatId, $task->getId());

		$updatedTask = $task->cloneWith(['chatId' => $chatId]);

		$alreadyExists = $result->getDataByKey('alreadyExists');

		if (!$alreadyExists)
		{
			$this->notifyForNewChat($task, $updatedTask);
		}

		$this->eventDispatcher::dispatch(new OnTaskMutedUserSyncEvent($task));

		return $updatedTask;
	}

	private function notifyForNewChat(Task $task, Task $updatedTask): void
	{
		$taskLegacyFeatureService = Container::getInstance()->getTaskLegacyFeatureService();

		if ($taskLegacyFeatureService->hasForumComments($task->getId()))
		{
			$this->chatNotification->notify(
				type: NotificationType::TaskHasForumComments,
				task: $updatedTask
			);
		}

		$legacyChatId = $taskLegacyFeatureService->getLegacyChatId($task->getId());

		if ($legacyChatId)
		{
			$this->chatNotification->notify(
				type: NotificationType::TaskHasLegacyChat,
				task: $updatedTask,
				args: ['chatId' => $legacyChatId],
			);
		}

		$this->chatNotification->notify(
			type: NotificationType::ChatCreatedForExistingTask,
			task: $updatedTask,
		);
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Esg\Handler;

use Bitrix\Tasks\V2\Internal\Repository\ChatRepositoryInterface;
use RuntimeException;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Event\Task\OnTaskMutedUserSyncEvent;
use Bitrix\Tasks\V2\Internal\EventDispatcher\EventDispatcher;
use Bitrix\Tasks\V2\Internal\Integration\Im\Chat;
use Bitrix\Tasks\V2\Internal\Integration\Im\ChatNotificationInterface;
use Bitrix\Tasks\V2\Internal\Integration\Im\NotificationType;
use Bitrix\Tasks\V2\Internal\Repository\UserRepositoryInterface;
use Bitrix\Tasks\V2\Public\Command\Task\AddTaskCommand;

class TaskAddHandler
{
	public function __construct(
		private readonly Chat $chatIntegration,
		private readonly UserRepositoryInterface $userRepository,
		private readonly ChatNotificationInterface $chatNotification,
		private readonly ChatRepositoryInterface $chatRepository,
		private readonly EventDispatcher $eventDispatcher,
	)
	{
	}

	public function handle(AddTaskCommand $command): Task
	{
		// create new chat
		$result = $this->chatIntegration->addChatByTaskId($command->task->getId());

		$chatId = $result->getId();
		if (!$result->isSuccess() || $chatId <= 0)
		{
			throw new RuntimeException('There was an error while saving task chat');
		}

		$this->chatRepository->save($chatId, $command->task->getId());

		$createdTask = $command->task->cloneWith(['chatId' => $chatId]);

		$this->chatNotification->notify(
			type: NotificationType::TaskCreated,
			task: $createdTask,
			args: ['triggeredBy' => $this->userRepository
				->getByIds([$command->config->getUserId()])
				->findOneById($command->config->getUserId())
			],
		);

		$this->eventDispatcher::dispatch(new OnTaskMutedUserSyncEvent($createdTask));

		return $createdTask;
	}
}

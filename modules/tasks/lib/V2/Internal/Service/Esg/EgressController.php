<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Esg;

use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Integration\Im\ChatNotificationInterface;
use Bitrix\Tasks\V2\Internal\Integration\Im\NotificationType;
use Bitrix\Tasks\V2\Internal\Repository\TaskReadRepositoryInterface;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Bitrix\Tasks\V2\Public\Command\Task\AddTaskCommand;
use Bitrix\Tasks\V2\Public\Command\Task\DeleteTaskCommand;
use Bitrix\Tasks\V2\Public\Command\Task\Tracking\StartTimerCommand;
use Bitrix\Tasks\V2\Public\Command\Task\Tracking\StopTimerCommand;
use Bitrix\Tasks\V2\Public\Command\Task\UpdateTaskCommand;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Integration\Im\Chat;
use Bitrix\Tasks\V2\Internal\Repository\UserRepositoryInterface;
use Exception;

class EgressController implements EgressInterface
{
	private Chat $chatIntegration;
	private UserRepositoryInterface $userRepository;
	private TaskReadRepositoryInterface $taskReadRepository;

	private ChatNotificationInterface $chatNotification;

	public function __construct(
		Chat $chatIntegration,
		UserRepositoryInterface $userRepository,
		TaskReadRepositoryInterface $taskReadRepository,
		ChatNotificationInterface $chatNotification,
	)
	{
		$this->chatIntegration = $chatIntegration;
		$this->userRepository = $userRepository;
		$this->taskReadRepository = $taskReadRepository;
		$this->chatNotification = $chatNotification;
	}

	public function processAddTaskCommand(AddTaskCommand $command): Task
	{
		// create new chat
		$chatId = $this->chatIntegration->addChat($command->task);

		if ($chatId === null)
		{
			throw new Exception('There was an error while saving task chat');
		}

		$createdTask = $command->task->cloneWith(['chatId' => $chatId]);

		$this->chatNotification->notify(
			type: NotificationType::TaskCreated,
			task: $createdTask,
			args: ['triggeredBy' => $this->userRepository->getByIds([$command->config->getUserId()])->getFirstEntity()],
		);

		return $createdTask;
	}

	public function createChatForExistingTask(Task $task): Task
	{
		// create new chat
		$chatId = $this->chatIntegration->addChat($task);

		if ($chatId === null)
		{
			throw new Exception('There was an error while creating new task chat');
		}

		$updatedTask = $task->cloneWith(['chatId' => $chatId]);

		$this->chatNotification->notify(
			type: NotificationType::ChatCreatedForExistingTask,
			task: $updatedTask,
		);

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

		return $updatedTask;
	}

	public function process(AbstractCommand $command): void
	{
		match (true)
		{
			$command instanceof UpdateTaskCommand => $this->processTaskUpdateCommand($command),
			$command instanceof DeleteTaskCommand => $this->processTaskDeleteCommand($command),
			$command instanceof StartTimerCommand => $this->processStartTimerCommand($command),
			$command instanceof StopTimerCommand => $this->processStopTimerCommand($command),
			default => '',
		};
	}

	protected function processTaskUpdateCommand(UpdateTaskCommand $command): void
	{
		$taskBeforeUpdate = $command->taskBeforeUpdate;

		if ($taskBeforeUpdate === null)
		{
			return;
		}

		$changes = $command->task->diff($taskBeforeUpdate);
		$triggeredBy = $this->userRepository->getByIds([$command->config->getUserId()])->getFirstEntity();

		foreach ($changes as $key => $change)
		{
			match ($key)
			{
				'responsible' => $this->handleTaskMembersChanged(
					task: $command->task,
					triggeredBy: $triggeredBy,
					taskBeforeUpdate: $taskBeforeUpdate,
					key: 'responsible',
				),
				'creator' => $this->handleTaskMembersChanged(
					task: $command->task,
					triggeredBy: $triggeredBy,
					taskBeforeUpdate: $taskBeforeUpdate,
					key: 'creator',
				),
				'deadlineTs' => $this->chatNotification->notify(
					type: NotificationType::DeadlineChanged,
					task: $command->task,
					args: ['triggeredBy' => $triggeredBy, 'oldDeadlineTs' => $taskBeforeUpdate->deadlineTs, 'newDeadlineTs' => $command->task->deadlineTs],
				),
				'auditors' => $this->handleTaskMembersChanged(
					task: $command->task,
					triggeredBy: $triggeredBy,
					taskBeforeUpdate: $taskBeforeUpdate,
					key: 'auditors',
				),
				'accomplices' => $this->handleTaskMembersChanged(
					task: $command->task,
					triggeredBy: $triggeredBy,
					taskBeforeUpdate: $taskBeforeUpdate,
					key: 'accomplices',
				),
				'group' => $this->chatNotification->notify(
					type: NotificationType::GroupChanged,
					task: $command->task,
					args: ['triggeredBy' => $triggeredBy, 'oldGroup' => $taskBeforeUpdate->group, 'newGroup' => $command->task->group],
				),
				'status' => $this->chatNotification->notify(
					type: NotificationType::TaskStatusChanged,
					task: $command->task,
					args: ['triggeredBy' => $triggeredBy, 'oldStatus' => $taskBeforeUpdate->status, 'newStatus' => $command->task->status],
				),
				default => '',
			};
		}
	}

	protected function handleTaskMembersChanged(Task $task, ?User $triggeredBy, Task $taskBeforeUpdate, string $key): void
	{
		match ($key)
		{
			'responsible' => $this->chatNotification->notify(
				type: NotificationType::ResponsibleChanged,
				task: $task,
				args: ['triggeredBy' => $triggeredBy, 'oldResponsible' => $taskBeforeUpdate->responsible, 'newResponsible' => $task->responsible],
			),
			'creator' => $this->chatNotification->notify(
				type: NotificationType::OwnerChanged,
				task: $task,
				args: ['triggeredBy' => $triggeredBy, 'oldOwner' => $taskBeforeUpdate->creator, 'newOwner' => $task->creator],
			),
			'auditors' => $this->chatNotification->notify(
				type: NotificationType::AuditorsChanged,
				task: $task,
				args: ['triggeredBy' => $triggeredBy, 'oldAuditors' => $taskBeforeUpdate->auditors, 'newAuditors' => $task->auditors],
			),
			'accomplices' => $this->chatNotification->notify(
				type: NotificationType::AccomplicesChanged,
				task: $task,
				args: ['triggeredBy' => $triggeredBy, 'oldAccomplices' => $taskBeforeUpdate->accomplices, 'newAccomplices' => $task->accomplices],
			),
			default => false,
		};

		$membersToAdd = array_values(array_diff($task->getMemberIds(), $taskBeforeUpdate->getMemberIds()));
		$membersToHide = array_values(array_diff($taskBeforeUpdate->getMemberIds(), $task->getMemberIds()));

		$this->chatIntegration->addChatMembers(task: $task, membersToAdd: $membersToAdd);
		$this->chatIntegration->hideChatMembers(task: $task, membersToHide: $membersToHide);
	}

	private function processTaskDeleteCommand(DeleteTaskCommand $command): void
	{
		// todo: ???
	}

	private function processStartTimerCommand(StartTimerCommand $command): void
	{
		$task = $this->taskReadRepository->getById($command->taskId);

		if (!$task)
		{
			return;
		}

		$triggeredBy = $this->userRepository->getByIds([$command->userId])->getFirstEntity();

		$this->chatNotification->notify(
			type: NotificationType::TaskTimerStarted,
			task: $task,
			args: ['triggeredBy' => $triggeredBy],
		);
	}

	private function processStopTimerCommand(StopTimerCommand $command): void
	{
		$task = $this->taskReadRepository->getById($command->taskId);

		if (!$task)
		{
			return;
		}

		$triggeredBy = $this->userRepository->getByIds([$command->userId])->getFirstEntity();

		$this->chatNotification->notify(
			type: NotificationType::TaskTimerStopped,
			task: $task,
			args: ['triggeredBy' => $triggeredBy],
		);
	}
}

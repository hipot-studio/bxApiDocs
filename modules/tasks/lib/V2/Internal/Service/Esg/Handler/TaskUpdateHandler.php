<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Esg\Handler;

use Bitrix\Tasks\V2\Internal\Entity\Group;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Event\Task\OnCreatorUpdatedEvent;
use Bitrix\Tasks\V2\Internal\EventDispatcher\EventDispatcher;
use Bitrix\Tasks\V2\Internal\Integration\Im\ChatNotificationInterface;
use Bitrix\Tasks\V2\Internal\Integration\Im\NotificationType;
use Bitrix\Tasks\V2\Internal\Integration\Im\Chat;
use Bitrix\Tasks\V2\Internal\Repository\UserRepositoryInterface;
use Bitrix\Tasks\V2\Public\Command\Task\UpdateTaskCommand;

class TaskUpdateHandler
{
	public function __construct(
		private readonly Chat                    $chatIntegration,
		private readonly UserRepositoryInterface $userRepository,
		private readonly ChatNotificationInterface $chatNotification,
		private readonly EventDispatcher $eventDispatcher,
	)
	{
	}

	public function handle(UpdateTaskCommand $command): void
	{
		$taskBeforeUpdate = $command->taskBeforeUpdate;

		if ($taskBeforeUpdate === null)
		{
			return;
		}

		$changes = $command->task->diff($taskBeforeUpdate);
		$triggeredBy = $this->userRepository
			->getByIds([$command->config->getUserId()])
			->findOneById($command->config->getUserId())
		;

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
					args: [
						'triggeredBy' => $triggeredBy,
						'oldDeadlineTs' => $taskBeforeUpdate->deadlineTs,
						'newDeadlineTs' => $command->task->deadlineTs
					],
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
				'group' => $this->handleGroupChanged(
					task: $command->task,
					triggeredBy: $triggeredBy,
					newGroup: $command->task->group,
					oldGroup: $taskBeforeUpdate->group,
				),
				'status' => $this->chatNotification->notify(
					type: NotificationType::TaskStatusChanged,
					task: $command->task,
					args: [
						'triggeredBy' => $triggeredBy,
						'oldStatus' => $taskBeforeUpdate->status,
						'newStatus' => $command->task->status,
					],
				),
				'title' => 	$this->chatIntegration->renameChat(
					task: $command->task,
					taskBeforeUpdate: $taskBeforeUpdate,
				),
				'description' => $this->chatNotification->notify(
					type: NotificationType::TaskDescriptionChanged,
					task: $command->task,
					args: [
						'triggeredBy' => $triggeredBy,
						'oldDescription' => $taskBeforeUpdate->description,
						'newDescription' => $command->task->description,
					],
				),
				'priority' => $this->chatNotification->notify(
					type: NotificationType::TaskPriorityChanged,
					task: $command->task,
					args: [
						'triggeredBy' => $triggeredBy,
						'priority' => $command->task->priority,
					],
				),
				'stage' => $this->handleTaskStageChanged(
					task: $command->task,
					taskBeforeUpdate: $taskBeforeUpdate,
					triggeredBy: $triggeredBy,
					taskChangesContext: $command->taskChangesContext,
				),
				'mark' => $this->chatNotification->notify(
					type: NotificationType::TaskMarkChanged,
					task: $command->task,
					args: [
						'triggeredBy' => $triggeredBy,
						'markBefore' => $taskBeforeUpdate->mark,
					],
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
				args: [
					'triggeredBy' => $triggeredBy,
					'oldResponsible' => $taskBeforeUpdate->responsible,
					'newResponsible' => $task->responsible,
					'isNewMember' => !$taskBeforeUpdate->getMembers()->findOneById($task->responsible->id),
				],
			),
			'creator' => $this->chatNotification->notify(
				type: NotificationType::OwnerChanged,
				task: $task,
				args: ['triggeredBy' => $triggeredBy, 'oldOwner' => $taskBeforeUpdate->creator, 'newOwner' => $task->creator],
			),
			'auditors' => $this->chatNotification->notify(
				type: NotificationType::AuditorsChanged,
				task: $task,
				args: [
					'triggeredBy' => $triggeredBy,
					'oldAuditors' => $taskBeforeUpdate->auditors,
					'newAuditors' => $task->auditors,
					'newAddMembers' => $task->auditors->diff($taskBeforeUpdate->getMembers())
				],
			),
			'accomplices' => $this->chatNotification->notify(
				type: NotificationType::AccomplicesChanged,
				task: $task,
				args: [
					'triggeredBy' => $triggeredBy,
					'oldAccomplices' => $taskBeforeUpdate->accomplices,
					'newAccomplices' => $task->accomplices,
					'newAddMembers' => $task->accomplices->diff($taskBeforeUpdate->getMembers())
				],
			),
			default => false,
		};

		$membersToAdd = array_values(array_diff($task->getMemberIds(), $taskBeforeUpdate->getMemberIds()));
		$membersToHide = array_values(array_diff($taskBeforeUpdate->getMemberIds(), $task->getMemberIds()));

		$this->chatIntegration->addChatMembers(task: $task, membersToAdd: $membersToAdd);
		$this->chatIntegration->hideChatMembers(task: $task, membersToHide: $membersToHide);

		if ('creator' === $key)
		{
			$this->eventDispatcher->dispatch(new OnCreatorUpdatedEvent(
				task: $task,
				newCreator: $task->creator,
				previousCreator: $taskBeforeUpdate->creator,
			));
		}
	}

	private function handleTaskStageChanged(
		Task $task,
		Task $taskBeforeUpdate,
		?User $triggeredBy,
		?Task $taskChangesContext,
	): void
	{
		if (!$task->group)
		{
			return;
		}

		if ($task->stage === null)
		{
			if ($task->status !== Task\Status::Completed)
			{
				$this->chatNotification->notify(
					type: NotificationType::TaskMovedToBacklog,
					task: $task,
					args: ['triggeredBy' => $triggeredBy],
				);
			}

			return;
		}

		// insert another group or another sprint - ignore notify
		if (
			$task->group->id !== $taskBeforeUpdate->group?->id
			|| ($taskBeforeUpdate->stage?->entityId && $task->stage->entityId !== $taskBeforeUpdate->stage->entityId)
		)
		{
			return;
		}

		// Scrum stage can change by side effect in update-in-update call (1 -> null, null -> 2).
		// If taskChangesContext has a stage, only notify when it matches the current stage;
		// otherwise skip the check (backward compatibility).
		$changedStageId = $taskChangesContext?->stage?->id;
		$isCurrentContext = $changedStageId !== null && $task->stage->id === $changedStageId;
		if (!$taskChangesContext || $isCurrentContext)
		{
			$this->chatNotification->notify(
				type: NotificationType::TaskStageChanged,
				task: $task,
				args: [
					'triggeredBy' => $triggeredBy,
					'newStage' => $task->stage,
				],
			);
		}
	}

	private function handleGroupChanged(
		Task $task,
		?User $triggeredBy = null,
		?Group $newGroup = null,
		?Group $oldGroup = null,
	): void
	{
		if ($oldGroup !== null && $newGroup !== null)
		{
			$this->chatNotification->notify(
				type: NotificationType::GroupChanged,
				task: $task,
				args: [
					'triggeredBy' => $triggeredBy,
					'newGroup' => $newGroup,
				],
			);

			return;
		}

		if ($oldGroup !== null && $newGroup === null)
		{
			$this->chatNotification->notify(
				type: NotificationType::GroupRemoved,
				task: $task,
				args: [
					'triggeredBy' => $triggeredBy,
					'group' => $oldGroup,
				],
			);

			return;
		}

		if ($newGroup !== null)
		{
			$this->chatNotification->notify(
				type: NotificationType::GroupAdded,
				task: $task,
				args: [
					'triggeredBy' => $triggeredBy,
					'group' => $newGroup,
				],
			);
		}
	}
}

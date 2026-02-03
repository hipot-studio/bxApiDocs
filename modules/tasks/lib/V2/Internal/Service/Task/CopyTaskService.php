<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task;

use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Control\Exception\TaskAddException;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Service\Task\CopyFileService;
use Bitrix\Tasks\V2\Internal\Repository\RelatedTaskRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\SubTaskRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TaskRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\CheckList\CopyCheckListService;
use Bitrix\Tasks\V2\Internal\Service\AddTaskService;
use Bitrix\Tasks\Control\Exception\TaskNotExistsException;
use Bitrix\Tasks\V2\Internal\Access\Service\TaskAccessService;
use Bitrix\Tasks\V2\Internal\Service\Reminder\CopyReminderService;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Config\AddConfig;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Copy\Config\CopyConfig;
use Bitrix\Tasks\V2\Internal\Service\Task\Gantt\CopyGanttDependenceService;

class CopyTaskService
{
	private const MAX_TASKS_TO_COPY = 50;

	public function __construct(
		private readonly TaskRepositoryInterface $taskRepository,
		private readonly SubTaskRepositoryInterface $subTaskRepository,
		private readonly RelatedTaskRepositoryInterface $relatedTaskRepository,
		private readonly AddTaskService $addTaskService,
		private readonly CopyCheckListService $copyCheckListService,
		private readonly CopyReminderService $copyReminderService,
		private readonly CopyGanttDependenceService $copyGanttDependenceService,
		private readonly TaskAccessService $taskAccessService,
		private readonly CopyFileService $copyFileService,
	)
	{
	}

	/**
	 * @throws TaskNotExistsException
	 * @throws TaskAddException
	 */
	public function copy(Entity\Task $task, CopyConfig $config): ?Entity\Task
	{
		if (!$this->taskRepository->isExists($task->getId()))
		{
			throw new TaskNotExistsException(
				Loc::getMessage('TASKS_COPY_TASK_SERVICE_TASK_NOT_FOUND')
			);
		}

		if ($config->targetTaskId)
		{
			$rootTask = $this->taskRepository->getById($config->targetTaskId);

			if (!$rootTask)
			{
				throw new TaskNotExistsException(
					Loc::getMessage('TASKS_COPY_TASK_SERVICE_TASK_NOT_FOUND')
				);
			}

			$this->copySubTasks($task, $rootTask, $config);
		}
		else
		{
			$parentTaskId = $task->parent?->getId();
			if (
				$parentTaskId > 0
				&& !$this->taskAccessService->canRead($config->userId, $parentTaskId)
			)
			{
				$parentTaskId = null;
			}

			$rootTask = $this->createTaskCopy($task, $config, $parentTaskId);
			if (!$rootTask)
			{
				$message =
					$this->taskAccessService->getUserError()?->getMessage()
					?? Loc::getMessage('TASKS_COPY_TASK_SERVICE_ACCESS_DENIED')
				;

				throw new TaskAddException($message);
			}

			if ($config->withSubTasks)
			{
				$this->copySubTasks($task, $rootTask, $config);
			}
		}

		return $this->taskRepository->getById($rootTask->getId());
	}

	private function copySubTasks(
		Entity\Task $sourceRootTask,
		Entity\Task $copiedRootTask,
		CopyConfig $config,
	): void
	{
		$copiedTasksCount = 1;

		$queue = [[$sourceRootTask, $copiedRootTask]];

		while (!empty($queue) && $copiedTasksCount < self::MAX_TASKS_TO_COPY)
		{
			[$sourceTask, $copiedTask] = array_shift($queue);

			$sourceSubTasks = $this->subTaskRepository->getByParentId($sourceTask->getId());

			foreach ($sourceSubTasks as $sourceSubTask)
			{
				if ($copiedTasksCount >= self::MAX_TASKS_TO_COPY)
				{
					return;
				}

				if (!$this->taskAccessService->canRead($config->userId, $sourceSubTask->getId()))
				{
					continue;
				}

				$copiedSubTask = $this->createTaskCopy($sourceSubTask, $config, $copiedTask->getId());
				if (!$copiedSubTask)
				{
					continue;
				}

				$copiedTasksCount++;

				$queue[] = [$sourceSubTask, $copiedSubTask];
			}
		}
	}

	private function createTaskCopy(
		Entity\Task $originalTask,
		CopyConfig $config,
		?int $parentTaskId = null,
	): ?Entity\Task
	{
		$preparedTask = $this->prepareTask(
			sourceTask: $originalTask,
			config: $config,
			parentTaskId: $parentTaskId,
		);

		if (!$this->taskAccessService->canSave($config->userId, $preparedTask))
		{
			return null;
		}

		$copiedTask = $this->addTaskService->add(
			task: $preparedTask,
			config: new AddConfig(
				userId: $config->userId,
				useConsistency: $config->useConsistency,
			),
		);

		$this->copyRelatedEntities(
			originalTask: $originalTask,
			copiedTask: $copiedTask,
			config: $config,
		);

		return $copiedTask;
	}

	private function copyRelatedEntities(
		Entity\Task $originalTask,
		Entity\Task $copiedTask,
		CopyConfig $config,
	): void
	{
		if ($config->withCheckLists)
		{
			$this->copyCheckListService->copy(
				fromTaskId: $originalTask->getId(),
				toTaskId: $copiedTask->getId(),
				userId: $config->userId,
				checkLists: $originalTask->checklist,
			);
		}

		if ($config->withReminders)
		{
			$this->copyReminderService->copy(
				fromTaskId: $originalTask->getId(),
				toTaskId: $copiedTask->getId(),
				userId: $config->userId,
			);
		}

		if ($config->withGanttLinks)
		{
			$this->copyGanttDependenceService->copy(
				fromTaskId: $originalTask->getId(),
				toTaskId: $copiedTask->getId(),
				userId: $config->userId,
			);
		}
	}

	private function prepareTask(
		Entity\Task $sourceTask,
		CopyConfig $config,
		?int $parentTaskId = null,
	): Entity\Task
	{
		$task = new Entity\Task(
			title: $sourceTask->title,
			creator: new Entity\User(id: $config->userId),
			responsible: $sourceTask->responsible,
			deadlineTs: $sourceTask->deadlineTs,
			needsControl: $sourceTask->needsControl,
			startPlanTs: $sourceTask->startPlanTs,
			endPlanTs: $sourceTask->endPlanTs,
			checklist: $sourceTask->checklist,
			group: $sourceTask->group,
			flow: $sourceTask->flow,
			priority: $sourceTask->priority,
			accomplices: $sourceTask->accomplices,
			auditors: $sourceTask->auditors,
			parent: $parentTaskId ? new Entity\Task(id: $parentTaskId) : null,
			plannedDuration: $sourceTask->plannedDuration,
			estimatedTime: $sourceTask->estimatedTime,
			xmlId: $sourceTask->xmlId,
			allowsChangeDeadline: $sourceTask->allowsChangeDeadline,
			allowsTimeTracking: $sourceTask->allowsTimeTracking,
			matchesWorkTime: $sourceTask->matchesWorkTime,
			siteId: $sourceTask->siteId,
			tags: $sourceTask->tags,
			userFields: $sourceTask->userFields,
			crmItemIds: $sourceTask->crmItemIds,
			requireResult: $sourceTask->requireResult,
		);

		if ($config->withAttachments)
		{
			[$fileIds, $description] = $this->copyFileService->copyAttachments(
				description: $sourceTask->description ?? '',
				userId: $config->userId,
				fileIds: $sourceTask->fileIds ?? [],
			);

			$task = $task->cloneWith([
				'fileIds' => $fileIds,
				'description' => $description,
			]);
		}

		if ($config->withRelatedTasks)
		{
			$relatedTaskIds =
				$sourceTask->dependsOn
				?? $this->relatedTaskRepository->getRelatedTaskIds((int)$sourceTask->getId())
			;

			$task = $task->cloneWith([
				'dependsOn' => $relatedTaskIds,
			]);
		}

		return $task;
	}
}

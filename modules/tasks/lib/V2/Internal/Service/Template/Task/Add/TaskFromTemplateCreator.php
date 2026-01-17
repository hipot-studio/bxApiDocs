<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Task\Add;

use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Control\Exception\TaskAddException;
use Bitrix\Tasks\Control\Exception\TaskNotExistsException;
use Bitrix\Tasks\V2\Internal\Access\Service\TaskAccessService;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Service\Task\CopyFileService;
use Bitrix\Tasks\V2\Internal\Service\AddTaskService;
use Bitrix\Tasks\V2\Internal\Service\Task;
use Bitrix\Tasks\V2\Internal\Service\Template\Task\Add\Config\AddTaskConfig;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskParams;
use Bitrix\Tasks\V2\Public\Provider\TaskProvider;

class TaskFromTemplateCreator
{
	public function __construct(
		private readonly TaskAccessService $taskAccessService,
		private readonly AddTaskService $addTaskService,
		private readonly TaskProvider $taskProvider,
		private readonly CopyFileService $copyFileService,
	)
	{

	}

	/**
	 * @throws TaskNotExistsException
	 * @throws TaskAddException
	 */
	public function add(Entity\Task $taskData, Entity\Template $template, AddTaskConfig $config): Entity\Task
	{
		if (!$this->taskAccessService->canSave($config->userId, $taskData))
		{
			$message =
				$this->taskAccessService->getUserError()?->getMessage()
				?? Loc::getMessage('TASKS_CREATE_TASK_FROM_TEMPLATE_ACCESS_DENIED')
			;

			throw new TaskAddException($message);
		}

		$preparedTaskData = $this->prepareAttachments($taskData, $config);

		$task = $this->addTaskService->add(
			task: $preparedTaskData,
			config: new Task\Action\Add\Config\AddConfig(userId: $config->userId),
		);

		if ($config->withSubTasks)
		{
			(new AddSubTasks($config))($task->id, $template->id);
		}

		$task = $this->taskProvider->get(
			new TaskParams(taskId: $task->id, userId: $config->userId),
		);

		if ($task === null)
		{
			throw new TaskNotExistsException(
				Loc::getMessage('TASKS_CREATE_TASK_FROM_TEMPLATE_TASK_NOT_FOUND')
			);
		}

		return $task;
	}

	private function prepareAttachments(Entity\Task $taskData, AddTaskConfig $config): Entity\Task
	{
		[$fileIds, $description] = $this->copyFileService->copyAttachments(
			description:     $taskData->description ?? '',
			userId:          $config->userId,
			fileIds:         $taskData->fileIds ?? [],
		);

		return $taskData->cloneWith([
			'fileIds' => $fileIds,
			'description' => $description,
		]);
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\Task\ArchiveTaskCommand;

use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Repository\TaskRepository\TaskRepository;
use Bitrix\Bizproc\Public\Service\Task\ArchiveTaskService;
use Bitrix\Main\Application;

class ArchiveTaskCommandHandler
{
	private TaskRepository $taskRepository;
	private ArchiveTaskService $archiveService;

	public function __construct()
	{
		$this->taskRepository = Container::getTaskRepository();
		$this->archiveService = Container::getArchiveTaskService();
	}

	public function __invoke(ArchiveTaskCommand $command): bool
	{
		$neededTasks = $this->taskRepository->getForArchive(
			select: ['ID'],
			limit: $command->limit
		);
		$neededTasksIds = $neededTasks->getEntityIds();
		if (empty($neededTasksIds))
		{
			return false;
		}

		$allTasks = $this->taskRepository->getTasksDataByIds(
			[
				'ID',
				'WORKFLOW_ID',
				'NAME',
				'DESCRIPTION',
				'STATUS',
				'MODIFIED',
				'CREATED_DATE',
				'TASK_USERS.USER_ID',
				'TASK_USERS.STATUS',
				'TASK_USERS.DATE_UPDATE',
			],
			$neededTasksIds
		);
		$groupedTasks = $allTasks->groupByWorkflowId();

		$connection = Application::getConnection();
		foreach ($groupedTasks as $workflowId => $tasks)
		{
			$connection->startTransaction();
			try
			{
				$this->archiveService->archiveTasks($workflowId, $tasks);
				$connection->commitTransaction();
			}
			catch (\Throwable $exception)
			{
				$connection->rollbackTransaction();
				throw $exception;
			}
		}

		return $command->limit === $neededTasks->count();
	}
}

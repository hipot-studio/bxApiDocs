<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\Task\ArchiveTaskCommand;

use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Repository\TaskRepository\TaskRepository;
use Bitrix\Bizproc\Public\Service\Task\ArchiveTaskService;
use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;

class ArchiveTaskCommandHandler
{
	private TaskRepository $taskRepository;
	private ArchiveTaskService $archiveService;

	public function __construct()
	{
		$this->taskRepository = Container::getTaskRepository();
		$this->archiveService = Container::getArchiveTaskService();
	}

	public function __invoke(ArchiveTaskCommand $command): ArchiveTaskHandlerResult
	{
		$afterDate = null;
		if ($command->afterDate !== null)
		{
			$afterDate = DateTime::createFromTimestamp($command->afterDate);
		}

		$neededTasksIds = $this->taskRepository->getTaskIdsForArchive($command->limit, $command->candidateLimit, $afterDate);
		if (empty($neededTasksIds))
		{
			return new ArchiveTaskHandlerResult();
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
			$neededTasksIds,
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

		$lastModified = $allTasks->getLastCollectionItem()?->getModified();

		return new ArchiveTaskHandlerResult(
			isReachedLimit: true,
			lastModified: $lastModified,
		);
	}
}

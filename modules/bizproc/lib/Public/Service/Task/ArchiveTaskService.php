<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\Task;

use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Entity\Task\Task;
use Bitrix\Bizproc\Internal\Entity\Task\TaskArchive\TaskArchive;
use Bitrix\Bizproc\Internal\Entity\Task\TaskArchive\TaskArchiveTasksCollection;
use Bitrix\Bizproc\Internal\Entity\Task\TaskArchive\TaskArchiveTasks;
use Bitrix\Bizproc\Internal\Entity\Task\TaskCollection;
use Bitrix\Bizproc\Internal\Entity\Task\TaskUser\TaskUser;
use Bitrix\Bizproc\Internal\Repository\TaskArchiveRepository\TaskArchiveRepository;
use Bitrix\Bizproc\Internal\Repository\TaskArchiveRepository\TaskArchiveTasksRepository;
use Bitrix\Bizproc\Internal\Repository\TaskRepository\TaskRepository;
use Bitrix\Main\Config\Option;

class ArchiveTaskService
{
	private const DEFAULT_CHUNK_SIZE = 10000;
	private TaskArchiveRepository $archiveRepository;
	private TaskArchiveTasksRepository $archiveTasksRepository;
	private TaskRepository $taskRepository;

	public function __construct(
		TaskArchiveRepository $archiveRepository,
		TaskArchiveTasksRepository $archiveTasksRepository,
		TaskRepository $taskRepository,
	)
	{
		$this->archiveRepository = $archiveRepository;
		$this->archiveTasksRepository = $archiveTasksRepository;
		$this->taskRepository = $taskRepository;
	}

	public function archiveTasks(string $workflowId, TaskCollection $tasks): void
	{
		$taskArchive =
			$this->archiveRepository->getByWorkflowId($workflowId)
			?? $this->getNewArchive($workflowId)
		;

		$chunkSize = (int)Option::get('bizproc', 'archive_bp_task_chunk_size', self::DEFAULT_CHUNK_SIZE);
		$overflowCount = max(0, $chunkSize - $taskArchive->getTasksCount());
		$tasksForOldArchive = $tasks->take($overflowCount);
		$this->addDataToArchive($taskArchive, $tasksForOldArchive);

		$tasksForNewArchive = $tasks->skip($overflowCount);
		if (!$tasksForNewArchive->isEmpty())
		{
			$newArchive = $this->getNewArchive($workflowId);
			$this->addDataToArchive($newArchive, $tasksForNewArchive);
		}

		$this->taskRepository->deleteTasksByIds($tasks->getEntityIds());
	}

	private function getNewArchive(string $workflowId): TaskArchive
	{
		return (new TaskArchive())->setWorkflowId($workflowId)->setTasksData([]);
	}

	private function addDataToArchive(TaskArchive $taskArchive, TaskCollection $tasks): void
	{
		$taskData = array_merge($taskArchive->getTasksData(), $this->getTaskData($tasks));
		$taskArchive->setTasksData($taskData);
		$archiveId = $this->archiveRepository->saveArchive($taskArchive)->getId();

		$links = $this->createArchiveTaskWorkflowLinks($tasks, $archiveId);
		$this->archiveTasksRepository->saveLinks($links);
	}

	private function getTaskData(TaskCollection $tasks): array
	{
		$taskData = [];
		/** @var Task $task */
		foreach ($tasks as $task)
		{
			$taskId = $task->getId();
			$taskData[$taskId] = $this->prepareTaskData($task);
		}

		return $taskData;
	}

	private function prepareTaskData(Task $task): array
	{
		$users = [];
		/** @var TaskUser $user */
		foreach ($task->getTaskUsers() as $user)
		{
			$userId = $user->getUserId();
			$users[$userId] = [
				$userId, // USER_ID
				$user->getStatus(), // STATUS
				$user->getDateUpdate(), // DATE_UPDATE
			];
		}

		return [
			$task->getId(), // ID
			$task->getName(), // NAME
			$task->getDescription(), // DESCRIPTION
			$task->getStatus(), // STATUS
			$task->getCreatedDate(), // CREATED_DATE
			$task->getModified(), // MODIFIED
			$users, // USERS
		];
	}

	private function createArchiveTaskWorkflowLinks(TaskCollection $tasks, int $archiveId): TaskArchiveTasksCollection
	{
		$taskArchiveTasksCollection = new TaskArchiveTasksCollection();
		/* @var Task $task */
		foreach ($tasks as $task)
		{
			$archiveTaskWorkflow =
				(new TaskArchiveTasks())
					->setTaskId($task->getId())
					->setArchiveId($archiveId)
					->setCompletedAt($task->getModified())
			;

			$taskArchiveTasksCollection->add($archiveTaskWorkflow);
		}

		return $taskArchiveTasksCollection;
	}

	public static function encodeTasksArchive(array $tasks): string
	{
		$taskData = \Bitrix\Main\Web\Json::encode($tasks);
		if (function_exists('gzcompress'))
		{
			$taskData = gzcompress($taskData);
		}

		return $taskData;
	}
}

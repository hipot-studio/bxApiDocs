<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\Task;

use Bitrix\Bizproc\Internal\Repository\TaskArchiveRepository\RestoreTaskArchiveRepository;
use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;

class RestoreTaskArchiveService
{
	private RestoreTaskArchiveRepository $repository;

	public function __construct()
	{
		$this->repository = new RestoreTaskArchiveRepository();
	}

	public function restoreChunk(int $archiveId, string $workflowId, string $tasksData, int $chunkSize): int
	{
		$remainingIds = $this->repository->getTaskIdsByArchiveId($archiveId, $chunkSize + 1);
		if (empty($remainingIds))
		{
			$this->repository->deleteArchive($archiveId);

			return 0;
		}

		$unArchiveService = new UnArchiveTaskService($tasksData);
		$allSorted = $unArchiveService->getTasks(sort: ['MODIFIED' => SORT_ASC], raw: true);

		$remainingSet = array_flip($remainingIds);
		$remaining = array_filter(
			$allSorted,
			static fn($task) => isset($remainingSet[$task[ArchiveTaskService::TASK_ID]]),
		);
		$chunk = array_slice($remaining, 0, $chunkSize, true);

		if (empty($chunk))
		{
			$this->repository->deleteArchive($archiveId);

			return 0;
		}

		$this->insertTasks($chunk, $workflowId);

		$taskIds = array_map(
			static fn($task) => (int)$task[ArchiveTaskService::TASK_ID],
			$chunk,
		);
		$this->repository->deleteTaskLinks($archiveId, $taskIds);

		if (count($chunk) >= count($remaining))
		{
			$this->repository->deleteArchive($archiveId);
		}

		return count($chunk);
	}

	private function insertTasks(array $tasks, string $workflowId): void
	{
		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();

		$userValuesList = [];
		$userColumns = '';

		foreach ($tasks as $taskRaw)
		{
			$taskId = (int)$taskRaw[ArchiveTaskService::TASK_ID];

			$taskFields = [
				'ID' => $taskId,
				'WORKFLOW_ID' => $workflowId,
				'ACTIVITY' => 'ARCHIVED',
				'ACTIVITY_NAME' => 'ARCHIVED',
				'CREATED_DATE' =>
					isset($taskRaw[ArchiveTaskService::TASK_CREATED_DATE])
						? DateTime::createFromTimestamp($taskRaw[ArchiveTaskService::TASK_CREATED_DATE])
						: null,
				'MODIFIED' => DateTime::createFromTimestamp($taskRaw[ArchiveTaskService::TASK_MODIFIED]),
				'NAME' => (string)($taskRaw[ArchiveTaskService::TASK_NAME] ?? ''),
				'DESCRIPTION' => (string)($taskRaw[ArchiveTaskService::TASK_DESCRIPTION] ?? ''),
				'STATUS' => (int)($taskRaw[ArchiveTaskService::TASK_STATUS] ?? 0),
				'IS_INLINE' => 'N',
				'DELEGATION_TYPE' => 0,
			];

			[$columns, $values] = $sqlHelper->prepareInsert('b_bp_task', $taskFields);
			$connection->queryExecute(
				$sqlHelper->getInsertIgnore('b_bp_task', "({$columns})", "VALUES ({$values})")
			);

			$users = $taskRaw[ArchiveTaskService::TASK_USERS] ?? [];
			foreach ($users as $userData)
			{
				$userId = (int)($userData[ArchiveTaskService::USER_ID] ?? 0);

				$userFields = [
					'USER_ID' => $userId,
					'TASK_ID' => $taskId,
					'STATUS' => (int)($userData[ArchiveTaskService::USER_STATUS] ?? 0),
					'DATE_UPDATE' =>
						isset($userData[ArchiveTaskService::USER_DATE_UPDATE])
							? DateTime::createFromTimestamp($userData[ArchiveTaskService::USER_DATE_UPDATE])
							: null
					,
					'ORIGINAL_USER_ID' => $userId,
				];

				[$userColumns, $values] = $sqlHelper->prepareInsert('b_bp_task_user', $userFields);
				$userValuesList[] = "({$values})";
			}
		}

		if (!empty($userValuesList))
		{
			$allValues = implode(', ', $userValuesList);
			$connection->queryExecute(
				$sqlHelper->getInsertIgnore('b_bp_task_user', "({$userColumns})", "VALUES {$allValues}")
			);
		}
	}
}

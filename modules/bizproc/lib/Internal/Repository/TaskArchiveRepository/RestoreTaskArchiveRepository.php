<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\TaskArchiveRepository;

use Bitrix\Bizproc\Internal\Model\TaskArchive\TaskArchiveTable;
use Bitrix\Bizproc\Internal\Model\TaskArchive\TaskArchiveTasksTable;
use Bitrix\Main\Type\DateTime;

class RestoreTaskArchiveRepository
{
	public function getWorkflowIdsWithRecentTasks(int $limit): array
	{
		$date = DateTime::createFromTimestamp(strtotime('-1 year'));

		$query = TaskArchiveTasksTable::query()
			->setSelect(['WORKFLOW_ID' => 'ARCHIVE.WORKFLOW_ID'])
			->where('COMPLETED_AT', '>=', $date)
			->setLimit($limit)
		;
		$rows = $query->fetchAll();

		return array_unique(array_column($rows, 'WORKFLOW_ID'));
	}

	public function getArchiveIdsByWorkflowId(string $workflowId, ?int $limit = null): array
	{
		$query = TaskArchiveTable::query()
			->setSelect(['ID'])
			->where('WORKFLOW_ID', $workflowId)
			->setOrder(['ID' => 'ASC'])
			->setLimit($limit)
		;

		return array_map('intval', array_column($query->fetchAll(), 'ID'));
	}

	public function getLastRecentArchiveId(string $workflowId): ?int
	{
		$date = DateTime::createFromTimestamp(strtotime('-1 year'));

		$query = TaskArchiveTasksTable::query()
			->setSelect(['ARCHIVE_ID'])
			->where('ARCHIVE.WORKFLOW_ID', $workflowId)
			->where('COMPLETED_AT', '>=', $date)
			->setOrder(['COMPLETED_AT' => 'DESC'])
			->setLimit(1)
		;
		$row = $query->fetch();

		return $row ? (int)$row['ARCHIVE_ID'] : null;
	}

	public function getArchiveData(int $archiveId): ?array
	{
		$row = TaskArchiveTable::query()
			->setSelect(['ID', 'WORKFLOW_ID', 'TASKS_DATA'])
			->where('ID', $archiveId)
			->fetch()
		;

		return $row ?: null;
	}

	public function getTaskIdsByArchiveId(int $archiveId, ?int $limit = null): array
	{
		$query = TaskArchiveTasksTable::query()
			->setSelect(['TASK_ID'])
			->where('ARCHIVE_ID', $archiveId)
			->setLimit($limit)
		;

		return array_column($query->fetchAll(), 'TASK_ID');
	}

	public function deleteTaskLinks(int $archiveId, array $taskIds): void
	{
		TaskArchiveTasksTable::deleteByFilter([
			'ARCHIVE_ID' => $archiveId,
			'@TASK_ID' => $taskIds,
		]);
	}

	public function deleteArchive(int $archiveId): void
	{
		TaskArchiveTable::deleteByFilter(['ID' => $archiveId]);
	}
}

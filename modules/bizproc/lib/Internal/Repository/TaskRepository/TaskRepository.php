<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\TaskRepository;

use Bitrix\Bizproc\Internal\Entity\Task\TaskCollection;
use Bitrix\Bizproc\Internal\Model\TaskArchive\TaskArchiveTable;
use Bitrix\Bizproc\Internal\Model\TaskArchive\TaskArchiveTasksTable;
use Bitrix\Bizproc\Internal\Repository\Mapper\TaskMapper;
use Bitrix\Bizproc\Workflow\Task\TaskTable;
use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;

class TaskRepository
{

	public function __construct(private readonly TaskMapper $mapper)
	{
	}

	public function getTasksDataByIds(array $select, array $taskIds): TaskCollection
	{
		$query =
			TaskTable::query()
				->setSelect($select)
				->whereIn('ID', $taskIds)
		;
		$ormTasks = $query->fetchCollection();

		return $this->mapper->convertCollectionFromOrm($ormTasks);
	}

	public function getTaskIdsForArchive(int $limit, int $candidateLimit, ?DateTime $afterDate = null): array
	{
		$date = DateTime::createFromTimestamp(strtotime('-1 year'));

		$query = TaskTable::query()
			->setSelect(['ID', 'WORKFLOW_ID', 'MODIFIED'])
			->where('MODIFIED', '<', $date)
			->whereNull('WORKFLOW_INSTANCE.ID')
			->whereNull('TASK_ARCHIVE.ID')
			->setOrder(['MODIFIED' => 'ASC'])
			->setLimit($candidateLimit)
		;

		if ($afterDate !== null)
		{
			$query->where('MODIFIED', '>=', $afterDate);
		}

		$candidates = $query->fetchAll();
		if (empty($candidates))
		{
			return [];
		}

		$workflowIds = array_unique(array_column($candidates, 'WORKFLOW_ID'));

		$workflowIds = array_diff($workflowIds, $this->getWorkflowsWithRecentArchivedTasks($workflowIds, $date));
		$workflowIds = array_diff($workflowIds, $this->getWorkflowsWithRecentTasks($workflowIds, $date));

		$allowedMap = array_flip($workflowIds);
		$filtered = array_filter($candidates, static fn($task) => isset($allowedMap[$task['WORKFLOW_ID']]));

		return array_map('intval', array_column(array_slice($filtered, 0, $limit), 'ID'));
	}

	public function deleteTasksByIds(array $taskIds): void
	{
		if (empty($taskIds))
		{
			return;
		}

		$connection = Application::getConnection();
		$ids = array_map('intval', $taskIds);
		$allIds = implode(', ', $ids);

		$connection->query("DELETE FROM b_bp_task_user WHERE TASK_ID IN ($allIds)");
		$connection->query("DELETE FROM b_bp_task WHERE ID IN ($allIds)");
	}

	private function getWorkflowsWithRecentTasks(array $workflowIds, DateTime $date): array
	{
		if (empty($workflowIds))
		{
			return [];
		}

		$query = TaskTable::query()
			->setSelect(['WORKFLOW_ID'])
			->whereIn('WORKFLOW_ID', $workflowIds)
			->where('MODIFIED', '>=', $date)
			->setGroup(['WORKFLOW_ID'])
		;

		return array_column($query->fetchAll(), 'WORKFLOW_ID');
	}

	private function getWorkflowsWithRecentArchivedTasks(array $workflowIds, DateTime $date): array
	{
		if (empty($workflowIds))
		{
			return [];
		}

		$query = TaskArchiveTable::query()
			->setSelect(['ID', 'WORKFLOW_ID'])
			->whereIn('WORKFLOW_ID', $workflowIds)
		;
		$archiveRows = $query->fetchAll();

		if (empty($archiveRows))
		{
			return [];
		}

		$archiveIdToWorkflow = array_column($archiveRows, 'WORKFLOW_ID', 'ID');

		$query = TaskArchiveTasksTable::query()
			->setSelect(['ARCHIVE_ID'])
			->whereIn('ARCHIVE_ID', array_keys($archiveIdToWorkflow))
			->where('COMPLETED_AT', '>=', $date)
			->setGroup(['ARCHIVE_ID'])
		;
		$recentRows = $query->fetchAll();

		$recentArchiveIds = array_column($recentRows, 'ARCHIVE_ID');

		return array_unique(array_intersect_key($archiveIdToWorkflow, array_flip($recentArchiveIds)));
	}
}

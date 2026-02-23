<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\TaskRepository;

use Bitrix\Bizproc\Internal\Entity\Task\TaskCollection;
use Bitrix\Bizproc\Internal\Repository\Mapper\TaskMapper;
use Bitrix\Bizproc\Workflow\Task\TaskTable;
use Bitrix\Main\Application;

class TaskRepository implements TaskRepositoryInterface
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

	public function getForArchive(array $select, array $filter = [], ?int $limit = null): TaskCollection
	{
		$query =
			TaskTable::query()
				->setSelect($select)
				->setFilter($filter)
				->whereNull('WORKFLOW_INSTANCE.ID')
				->whereNull('TASK_ARCHIVE.ID')
				->setLimit($limit)
		;
		$ormTasks = $query->fetchCollection();

		return $this->mapper->convertCollectionFromOrm($ormTasks);
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
}

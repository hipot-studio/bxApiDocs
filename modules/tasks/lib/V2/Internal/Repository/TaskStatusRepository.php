<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\Internals\TaskTable;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\TaskStatusMapper;

class TaskStatusRepository
{
	public function __construct(
		private readonly TaskStatusMapper $taskStatusMapper,
	)
	{
	}

	public function getById(int $taskId): ?Entity\Task
	{
		$status = TaskTable::query()
			->setSelect(['STATUS'])
			->where('ID', $taskId)
			->fetch()
		;

		if ($status === false)
		{
			return null;
		}

		return new Entity\Task(
			id: $taskId,
			status: $this->taskStatusMapper->mapToEnum((int)$status['STATUS']),
		);
	}
}

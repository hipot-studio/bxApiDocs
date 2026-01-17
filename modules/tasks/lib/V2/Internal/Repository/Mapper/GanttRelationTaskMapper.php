<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper;

use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\TaskCollection;

class GanttRelationTaskMapper
{
	public function mapToCollection(
		array $tasks,
		?array $rights = null,
		?array $tasksGanttLinks = null,
	): TaskCollection
	{
		$entities = [];
		foreach ($tasks as $task)
		{
			$taskId = (int)($task['ID'] ?? 0);

			$entities[]= $this->mapToEntity(
				task: $task,
				rights: $rights[$taskId] ?? null,
				ganttLinks: $tasksGanttLinks[$taskId],
			);
		}

		return new TaskCollection(...$entities);
	}

	public function mapToEntity(
		array $task,
		?array $rights = null,
		?array $ganttLinks = null,
	): Task
	{
		$taskId = (int)($task['ID'] ?? 0);

		return new Task(
			id: $taskId,
			title: $task['TITLE'] ?? '',
			rights: $rights,
			ganttLinks: $ganttLinks,
		);
	}
}

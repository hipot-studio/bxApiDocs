<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper;

use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\V2\Internal\Entity\Group;
use Bitrix\Tasks\V2\Internal\Entity\GroupCollection;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\TaskCollection;

class GanttRelationTaskMapper
{
	public function __construct(
		private readonly TaskStatusMapper $taskStatusMapper,
	)
	{

	}

	public function mapToCollection(
		array $tasks,
		?array $rights = null,
		?array $tasksGanttLinks = null,
		?GroupCollection $groups = null,
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
				group: $groups?->findOneById((int)($task['GROUP_ID'] ?? 0)),
			);
		}

		return new TaskCollection(...$entities);
	}

	public function mapToEntity(
		array $task,
		?array $rights = null,
		?array $ganttLinks = null,
		?Group $group = null,
	): Task
	{
		$taskId = (int)($task['ID'] ?? 0);

		return new Task(
			id: $taskId,
			title: $task['TITLE'] ?? '',
			group: $group,
			status: $this->taskStatusMapper->mapToEnum((int)($task['STATUS'] ?? 0)),
			changedTs: ($task['CHANGED_DATE'] ?? null) instanceof DateTime ? $task['CHANGED_DATE']->getTimestamp() : null,
			activityTs: ($task['ACTIVITY_DATE'] ?? null) instanceof DateTime ? $task['ACTIVITY_DATE']->getTimestamp() : null,
			rights: $rights,
			ganttLinks: $ganttLinks,
		);
	}
}

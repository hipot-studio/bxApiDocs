<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Factory;

use Bitrix\Im\V2\Entity\Task\TaskItem;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\TaskStatusMapper;

class TaskItemFactory
{
	private TaskStatusMapper $statusMapper;

	public function __construct()
	{
		$this->statusMapper = new TaskStatusMapper();
	}

	public function createFromTask(Task $task): TaskItem
	{
		$taskStatus = $this->statusMapper->mapFromEnum($task->status);
		$deadline = $task->deadlineTs
			? DateTime::createFromTimestamp($task->deadlineTs)
			: null
		;

		return (new TaskItem())
			->setTaskId($task->id)
			->setTitle($task->title)
			->setDeadline($deadline)
			->setStatus($taskStatus)
			->setCreatorId($task->creator->id)
			->setResponsibleId($task->responsible->id)
			->setMembersIds($task->getMemberIds())
		;
	}
}

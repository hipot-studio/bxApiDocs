<?php

declare(strict_types=1);

namespace Bitrix\Tasks\DI;

use Bitrix\Tasks\Control\Task;
use Bitrix\Tasks\Provider\TaskList;

final class Container extends AbstractContainer
{
	public function getTaskService(int $userId): Task
	{
		return $this->getRuntimeObject(
			static fn (): Task => new Task($userId),
			'tasks.task.service.' . $userId,
			['userId' => $userId],
		);
	}

	public function getTaskProvider(): TaskList
	{
		return $this->getRegisteredObject(TaskList::class);
	}
}
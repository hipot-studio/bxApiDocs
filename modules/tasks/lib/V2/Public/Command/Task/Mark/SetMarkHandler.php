<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Mark;

use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Service\UpdateTaskService;

class SetMarkHandler
{
	public function __construct(
		private readonly UpdateTaskService $updateTaskService,
	)
	{

	}
	public function __invoke(SetMarkCommand $command): Task
	{
		$task = new Task(
			id: $command->taskId,
			mark: $command->mark,
		);

		return $this->updateTaskService->update($task, $command->config);
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Relation;

use Bitrix\Tasks\Control\Exception\TaskNotFoundException;
use Bitrix\Tasks\V2\Internal\Entity\TaskCollection;
use Bitrix\Tasks\V2\Internal\Service\Task\MultiTaskService;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskParams;
use Bitrix\Tasks\V2\Public\Provider\TaskProvider;

class AddMultiTaskChildrenHandler
{
	public function __construct(
		private readonly TaskProvider $taskProvider,
		private readonly MultiTaskService $multiTaskService,
	)
	{

	}

	public function __invoke(AddMultiTaskChildrenCommand $command): TaskCollection
	{
		$task = $this->taskProvider->get(new TaskParams(
			taskId: $command->taskId,
			userId: $command->config->userId,
			checkLists: false,
		));

		if ($task === null)
		{
			throw new TaskNotFoundException();
		}

		return $this->multiTaskService->add(
			$task,
			$command->userIds,
			$command->config->userId,
		);
	}
}

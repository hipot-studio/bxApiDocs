<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Task;

use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Access\Task\Permission;
use Bitrix\Tasks\V2\Public\Command\Task\Description\UpdateDescriptionCommand;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskParams;
use Bitrix\Tasks\V2\Public\Provider\TaskProvider;

class Description extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Task.Description.update
	 */
	public function updateAction(
		#[Permission\Update]
		Entity\Task $task,
		TaskProvider $taskProvider,
	): ?Entity\Task
	{
		return $this->update(
			task: $task,
			taskProvider: $taskProvider,
			forceUpdate: false,
		);
	}

	/**
	 * @ajaxAction tasks.V2.Task.Description.forceUpdate
	 */
	public function forceUpdateAction(
		#[Permission\Update]
		Entity\Task $task,
		TaskProvider $taskProvider,
	): ?Entity\Task
	{
		return $this->update(
			task: $task,
			taskProvider: $taskProvider,
			forceUpdate: true,
		);
	}

	private function update(
		Entity\Task $task,
		TaskProvider $taskProvider,
		bool $forceUpdate,
	): ?Entity\Task
	{
		$result = (new UpdateDescriptionCommand(
			task: $task,
			forceUpdate: $forceUpdate,
			userId: $this->userId,
			useConsistency: true,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $taskProvider->get(TaskParams::mapFromIds($task->getId(), $this->userId));
	}
}

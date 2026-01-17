<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Task\Stakeholder;

use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Access\Task\Creator\Permission;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Public\Command\Task\Stakeholder\UpdateCreatorCommand;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskParams;
use Bitrix\Tasks\V2\Public\Provider\TaskProvider;

class Creator extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Task.Stakeholder.Creator.update
	 */
	public function updateAction(
		#[Permission\ChangeDirector]
		Entity\Task $task,
		TaskProvider $taskProvider,
	): ?Entity\EntityInterface
	{
		$config = new UpdateConfig(
			userId: $this->userId,
			useConsistency: true,
		);

		$result = (new UpdateCreatorCommand(
			taskId: $task->getId(),
			creatorId: (int)$task->creator?->getId(),
			responsibleId: (int)$task->responsible?->getId(),
			config: $config,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $taskProvider->get(TaskParams::mapFromIds($task->getId(), $this->userId, ['members' => true]));
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Task\Stakeholder;

use Bitrix\Tasks\V2\Public\Command\Task\Stakeholder\SetAccomplicesCommand;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Access\Task\Accomplice\Permission;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Public\Provider\TaskProvider;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskParams;

class Accomplice extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Task.Stakeholder.Accomplice.set
	 */
	public function setAction(
		#[Permission\Update]
		Entity\Task $task,
		TaskProvider $taskProvider,
	): ?Entity\EntityInterface
	{
		$config = new UpdateConfig(
			userId: $this->userId,
			useConsistency: true,
		);

		$result = (new SetAccomplicesCommand(
			taskId: $task->getId(),
			accompliceIds: (array)$task->accomplices?->getIdList(),
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

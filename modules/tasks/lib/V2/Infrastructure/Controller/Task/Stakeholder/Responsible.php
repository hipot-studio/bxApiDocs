<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Task\Stakeholder;

use Bitrix\Tasks\V2\Public\Command\Task\Stakeholder\DelegateCommand;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Access\Task\Responsible\Permission;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskParams;
use Bitrix\Tasks\V2\Public\Provider\TaskProvider;

class Responsible extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Task.Stakeholder.Responsible.delegate
	 */
	public function delegateAction(
		#[Permission\Delegate]
		Entity\Task $task,
		TaskProvider $taskProvider,
	): ?Entity\EntityInterface
	{
		$config = new UpdateConfig(
			userId: $this->userId,
			useConsistency: true,
		);

		$result = (new DelegateCommand(
			taskId: $task->getId(),
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

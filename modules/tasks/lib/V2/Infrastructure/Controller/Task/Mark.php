<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Task;

use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Access\Task\Mark\Permission\Set;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Public\Command\Task\Mark\SetMarkCommand;

class Mark extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Task.Mark.set
	 */
	public function setAction(
		#[Set]
		Task $task,
	): ?bool
	{
		$result = (new SetMarkCommand(
			taskId: (int)$task->getId(),
			mark: $task->mark ?? Task\Mark::None,
			config: new UpdateConfig(userId: $this->userId, useConsistency: true)
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return true;
	}
}

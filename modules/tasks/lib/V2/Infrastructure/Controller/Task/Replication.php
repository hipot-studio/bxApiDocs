<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Task;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Public\Command\Task\Replication\CreateReplicationTemplateCommand;
use Bitrix\Tasks\V2\Internal\Access\Task\Permission;
use Bitrix\Tasks\V2\Public\Command\Task\Replication\SetReplicationStateCommand;

class Replication extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Task.Replication.add
	 */
	public function addAction(
		#[Permission\Update]
		Entity\Task $task,
	): ?array
	{
		$result = (new CreateReplicationTemplateCommand(
			task: $task,
			userId: $this->userId,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	/**
	 * @ajaxAction tasks.V2.Task.Replication.setState
	 */
	public function setStateAction(
		#[Permission\Update]
		Entity\Task $task,
	): bool
	{
		$result = (new SetReplicationStateCommand(
			task: $task,
			userId: $this->userId,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return false;
		}

		return true;
	}
}

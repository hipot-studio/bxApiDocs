<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Task\Stakeholder;

use Bitrix\Tasks\V2\Public\Command\Task\Stakeholder\AddAuditorsCommand;
use Bitrix\Tasks\V2\Public\Command\Task\Stakeholder\DeleteAuditorsCommand;
use Bitrix\Tasks\V2\Public\Command\Task\Stakeholder\SetAuditorsCommand;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Access\Task\Auditor\Permission;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Public\Provider\TaskProvider;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskParams;

class Auditor extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Task.Stakeholder.Auditor.add
	 */
	public function addAction(
		#[Permission\Add]
		Entity\Task $task,
		TaskProvider $taskProvider,
	): ?Entity\EntityInterface
	{
		$config = new UpdateConfig(
			userId: $this->userId,
			useConsistency: true,
		);

		$result = (new AddAuditorsCommand(
			taskId: (int)$task->getId(),
			auditorIds: (array)$task->auditors?->getIdList(),
			config: $config,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $taskProvider->get(TaskParams::mapFromIds($task->getId(), $this->userId, ['members' => true]));
	}

	/**
	 * @ajaxAction tasks.V2.Task.Stakeholder.Auditor.delete
	 */
	public function deleteAction(
		#[Permission\Update]
		Entity\Task $task,
		TaskProvider $taskProvider,
	): ?Entity\EntityInterface
	{
		$config = new UpdateConfig(
			userId: $this->userId,
			useConsistency: true,
		);

		$result = (new DeleteAuditorsCommand(
			taskId: (int)$task->getId(),
			auditorIds: (array)$task->auditors?->getIdList(),
			config: $config,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $taskProvider->get(TaskParams::mapFromIds($task->getId(), $this->userId, ['members' => true]));
	}

	/**
	 * @ajaxAction tasks.V2.Task.Stakeholder.Auditor.set
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

		$result = (new SetAuditorsCommand(
			taskId: $task->getId(),
			auditorIds: (array)$task->auditors?->getIdList(),
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

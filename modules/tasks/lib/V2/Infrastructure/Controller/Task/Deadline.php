<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Task;

use Bitrix\Tasks\V2\Internal\Access;
use Bitrix\Tasks\V2\Public\Command\Task\Deadline\CleanChangeLogCommand;
use Bitrix\Tasks\V2\Public\Command\Task\Deadline\UpdateDeadlineCommand;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Public\Provider\DeadlineProvider;
use Bitrix\Tasks\V2\Public\Provider\TaskProvider;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskParams;

class Deadline extends BaseController
{
	/**
	 * @ajaxAction tasks.v2.Task.Deadline.update
	 */
	public function updateAction(
		#[Access\Task\Deadline\Permission\Update]
		Entity\Task $task,
		TaskProvider $taskProvider,
	): ?Entity\EntityInterface
	{
		$updateConfig = new UpdateConfig(
			userId: $this->userId,
			useConsistency: true,
		);

		$result = (new UpdateDeadlineCommand(
			taskId: $task->getId(),
			deadlineTs: $task->deadlineTs,
			updateConfig: $updateConfig,
			reason: $task->deadlineChangeReason,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $taskProvider->get(
			TaskParams::mapFromIds(
				$task->getId(),
				$this->userId,
				['parameters' => true],
			)
		);
	}

	/**
	 * @ajaxAction tasks.v2.Task.Deadline.cleanChangeLog
	 */
	public function cleanChangeLogAction(
		#[Access\Task\Deadline\Permission\Update]
		Entity\Task $task,
	): bool
	{
		$result = (new CleanChangeLogCommand($task->getId()))->run();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return false;
		}

		return true;
	}

	/**
	 * @ajaxAction tasks.v2.Task.Deadline.getDeadlineChangeCount
	 */
	public function getDeadlineChangeCountAction(
		#[Access\Task\Permission\Read]
		Entity\Task $task,
		DeadlineProvider $deadlineProvider,
	): int
	{
		return $deadlineProvider->getDeadlineChangeCount($this->userId, $task->getId());
	}
}

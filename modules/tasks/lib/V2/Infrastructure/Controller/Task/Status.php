<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Task;

use Bitrix\Tasks\V2\Public\Command\Task\Status\ApproveTaskCommand;
use Bitrix\Tasks\V2\Public\Command\Task\Status\CompleteTaskCommand;
use Bitrix\Tasks\V2\Public\Command\Task\Status\DeferTaskCommand;
use Bitrix\Tasks\V2\Public\Command\Task\Status\DisapproveTaskCommand;
use Bitrix\Tasks\V2\Public\Command\Task\Status\PauseTaskCommand;
use Bitrix\Tasks\V2\Public\Command\Task\Status\RenewTaskCommand;
use Bitrix\Tasks\V2\Public\Command\Task\Status\StartTaskCommand;
use Bitrix\Tasks\V2\Public\Command\Task\Status\TakeTaskCommand;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Access\Task\Status\Permission;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskParams;
use Bitrix\Tasks\V2\Public\Provider\TaskProvider;

class Status extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Task.Status.start
	 */
	public function startAction(
		#[Permission\Start]
		Entity\Task $task,
		TaskProvider $taskProvider,
	): ?Entity\EntityInterface
	{
		$config = new UpdateConfig(
			userId: $this->userId,
			useConsistency: true,
		);

		$result = (new StartTaskCommand(
			taskId: $task->getId(),
			config: $config,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $taskProvider->get(TaskParams::mapFromIds($task->getId(), $this->userId));
	}

	/**
	 * @ajaxAction tasks.V2.Task.Status.take
	 */
	public function takeAction(
		#[Permission\Take]
		Entity\Task $task,
		TaskProvider $taskProvider,
	): ?Entity\EntityInterface
	{
		$config = new UpdateConfig(
			userId: $this->userId,
			useConsistency: true,
		);

		$result = (new TakeTaskCommand(
			taskId: $task->getId(),
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
	 * @ajaxAction tasks.V2.Task.Status.disapprove
	 */
	public function disapproveAction(
		#[Permission\Disapprove]
		Entity\Task $task,
		TaskProvider $taskProvider,
	): ?Entity\EntityInterface
	{
		$config = new UpdateConfig(
			userId: $this->userId,
			useConsistency: true,
		);

		$result = (new DisapproveTaskCommand(
			taskId: $task->getId(),
			config: $config,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $taskProvider->get(TaskParams::mapFromIds($task->getId(), $this->userId));
	}

	/**
	 * @ajaxAction tasks.V2.Task.Status.defer
	 */
	public function deferAction(
		#[Permission\Defer]
		Entity\Task $task,
		TaskProvider $taskProvider,
	): ?Entity\EntityInterface
	{
		$config = new UpdateConfig(
			userId: $this->userId,
			useConsistency: true,
		);

		$result = (new DeferTaskCommand(
			taskId: $task->getId(),
			config: $config,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $taskProvider->get(TaskParams::mapFromIds($task->getId(), $this->userId));
	}

	/**
	 * @ajaxAction tasks.V2.Task.Status.approve
	 */
	public function approveAction(
		#[Permission\Approve]
		Entity\Task $task,
		TaskProvider $taskProvider,
	): ?Entity\EntityInterface
	{
		$config = new UpdateConfig(
			userId: $this->userId,
			useConsistency: true,
		);

		$result = (new ApproveTaskCommand(
			taskId: $task->getId(),
			config: $config,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $taskProvider->get(TaskParams::mapFromIds($task->getId(), $this->userId));
	}

	/**
	 * @ajaxAction tasks.V2.Task.Status.pause
	 */
	public function pauseAction(
		#[Permission\Pause]
		Entity\Task $task,
		TaskProvider $taskProvider,
	): ?Entity\EntityInterface
	{
		$config = new UpdateConfig(
			userId: $this->userId,
			useConsistency: true,
		);

		$result = (new PauseTaskCommand(
			taskId: $task->getId(),
			config: $config,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $taskProvider->get(TaskParams::mapFromIds($task->getId(), $this->userId));
	}

	/**
	 * @ajaxAction tasks.V2.Task.Status.complete
	 */
	public function completeAction(
		#[Permission\Complete]
		Entity\Task $task,
		TaskProvider $taskProvider,
	): ?Entity\EntityInterface
	{
		$config = new UpdateConfig(
			userId: $this->userId,
			useConsistency: true,
		);

		$result = (new CompleteTaskCommand(
			taskId: $task->getId(),
			config: $config,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $taskProvider->get(TaskParams::mapFromIds($task->getId(), $this->userId));
	}

	/**
	 * @ajaxAction tasks.V2.Task.Status.renew
	 */
	public function renewAction(
		#[Permission\Renew]
		Entity\Task $task,
		TaskProvider $taskProvider,
	): ?Entity\EntityInterface
	{
		$config = new UpdateConfig(
			userId: $this->userId,
			useConsistency: true,
		);

		$result = (new RenewTaskCommand(
			taskId: $task->getId(),
			config: $config,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $taskProvider->get(TaskParams::mapFromIds($task->getId(), $this->userId));
	}
}

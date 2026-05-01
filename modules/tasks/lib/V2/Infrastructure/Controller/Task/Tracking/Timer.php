<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Task\Tracking;

use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\TaskAccessController;
use Bitrix\Tasks\V2\Internal\Access\Factory\AccessControllerTrait;
use Bitrix\Tasks\V2\Internal\Access\Factory\Type;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Public\Command\Task\Tracking\StartTimerCommand;
use Bitrix\Tasks\V2\Public\Command\Task\Tracking\StopTimerCommand;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Access\Task\Permission;
use Bitrix\Tasks\V2\Internal\Access\Task\Tracking;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskParams;
use Bitrix\Tasks\V2\Public\Provider\TaskProvider;
use Bitrix\Tasks\V2\Public\Provider\TimerProvider;

class Timer extends BaseController
{
	use AccessControllerTrait;

	/**
	 * @ajaxAction tasks.V2.Task.Tracking.Timer.start
	 */
	public function startAction(
		#[Permission\Read]
		#[Tracking\Permission\Track]
		Entity\Task $task,
		TaskProvider $taskProvider,
	): ?Entity\EntityInterface
	{
		$userId = $this->userId;
		$taskId = $task->getId();

		$accessController = $this->getAccessController(Type::Task, $this->getAccessContext());

		$result = (new StartTimerCommand(
			userId: $userId,
			taskId: $taskId,
			syncPlan: true,
			canStart: $accessController->checkByItemId(ActionDictionary::ACTION_TASK_START, $taskId),
			canRenew: $accessController->checkByItemId(ActionDictionary::ACTION_TASK_RENEW, $taskId),
			useConsistency: true,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $taskProvider->get(TaskParams::mapFromIds($task->getId(), $this->userId));
	}

	/**
	 * @ajaxAction tasks.V2.Task.Tracking.Timer.stop
	 */
	public function stopAction(
		#[Permission\Read]
		#[Tracking\Permission\Track]
		Entity\Task $task,
		TaskProvider $taskProvider,
	): ?Entity\EntityInterface
	{
		$userId = $this->userId;
		$taskId = $task->getId();

		$accessController = $this->getAccessController(Type::Task, $this->getAccessContext());

		$result = (new StopTimerCommand(
			userId: $userId,
			taskId: $taskId,
			canStop: $accessController->checkByItemId(ActionDictionary::ACTION_TASK_PAUSE, $taskId),
			useConsistency: true,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $taskProvider->get(TaskParams::mapFromIds($task->getId(), $this->userId));
	}

	/**
	 * @ajaxAction tasks.V2.Task.Tracking.Timer.getTaskWithActiveTimer
	 */
	public function getTaskWithActiveTimerAction(
		TaskProvider $taskProvider,
		TimerProvider $timerProvider,
	): ?Task
	{
		$timer = $timerProvider->getActiveTimer($this->userId);
		if (!$timer)
		{
			return null;
		}

		if (!TaskAccessController::can($this->userId, ActionDictionary::ACTION_TASK_READ, $timer->taskId))
		{
			return null;
		}

		return $taskProvider->get(TaskParams::mapFromIds($timer->taskId, $this->userId));
	}
}

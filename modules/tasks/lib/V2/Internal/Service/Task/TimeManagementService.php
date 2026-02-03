<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task;

use Bitrix\Tasks\Control\Exception\TaskNotFoundException;
use Bitrix\Tasks\Integration\Pull\PushCommand;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\Task\Elapsed\Source;
use Bitrix\Tasks\V2\Internal\Entity\Task\ElapsedTime;
use Bitrix\Tasks\V2\Internal\Entity\Task\Timer;
use Bitrix\Tasks\V2\Internal\Integration\Im\ChatNotificationInterface;
use Bitrix\Tasks\V2\Internal\Integration\Im\NotificationType;
use Bitrix\Tasks\V2\Internal\Repository\TimerRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\UserRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\CacheService;
use Bitrix\Tasks\V2\Internal\Service\Esg\EgressInterface;
use Bitrix\Tasks\V2\Internal\Service\PushService;
use Bitrix\Tasks\V2\Internal\Entity\Task\Status;
use Bitrix\Tasks\V2\Internal\Entity\UserCollection;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Internal\Repository\ElapsedTimeRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TaskRepositoryInterface;
use Bitrix\Tasks\Util\User;
use Bitrix\Tasks\V2\Public\Command\Task\Tracking\StartTimerCommand;
use Bitrix\Tasks\V2\Public\Command\Task\Tracking\StopTimerCommand;
use Bitrix\Tasks\V2\Public\Provider\TaskElapsedTimeProvider;

class TimeManagementService
{
	public function __construct(
		private readonly TaskRepositoryInterface $taskRepository,
		private readonly TimerRepositoryInterface $timerRepository,
		private readonly ElapsedTimeRepositoryInterface $elapsedTimeRepository,
		private readonly TimerService $timerService,
		private readonly PlannerService $plannerService,
		private readonly StatusService $statusService,
		private readonly ElapsedTimeService $elapsedTimeService,
		private readonly PushService $pushService,
		private readonly CacheService $cacheService,
		private readonly EgressInterface $egressController,
		private readonly ChatNotificationInterface $chatNotification,
		private readonly TaskElapsedTimeProvider $elapsedTimeProvider,
		private readonly UserRepositoryInterface $userRepository,
	)
	{

	}

	public function startTimer(
		int $userId,
		int $taskId,
		bool $syncPlan = true,
		bool $canStart = false,
		bool $canRenew = false,
	): Timer
	{
		$this->stopTimer($userId);

		$task = $this->taskRepository->getById($taskId);
		if ($task === null)
		{
			throw new TaskNotFoundException('Task not found');
		}

		$timer = $this->timerService->start($userId, $taskId);

		$affectedUsers = $this->getAffectedUserIds($task, $userId);

		$this->cacheService->clearByTagMulti('tasks_user', $affectedUsers);

		if ($syncPlan)
		{
			$this->plannerService->merge($userId, [$taskId], []);
		}

		if ($task->status !== Status::InProgress)
		{
			if ($canStart)
			{
				$this->statusService->start($taskId, new UpdateConfig($userId));
			}
			elseif ($canRenew)
			{
				$this->statusService->renew($taskId, new UpdateConfig($userId));
			}
		}

		$timeSpent = $this->elapsedTimeRepository->getSum($taskId);

		$parameters = [
			'taskId' => $taskId,
			'timeElapsed' => $timeSpent + (time() - (int)$timer->startedAtTs),
		];

		$this->pushService->addEventByParameters(
			UserCollection::mapFromIds([$userId]),
			PushCommand::TASK_TIMER_STARTED,
			$parameters,
		);

		$this->egressController->process(
			new StartTimerCommand(
				userId: $userId,
				taskId: $taskId,
				syncPlan: $syncPlan,
				canStart: $canStart,
				canRenew: $canRenew,
			)
		);

		return $timer;
	}

	public function stopTimer(int $userId, int $taskId = 0, bool $sendNotification = true): ?Timer
	{
		$timer = $this->timerService->stop($userId, $taskId);
		if ($timer === null || $timer->seconds <= 0)
		{
			return null;
		}

		$startTs = $timer->startedAtTs;
		$stopTs = $startTs + $timer->seconds;

		$elapsedTime = new ElapsedTime(
			userId:      $userId,
			taskId:      $timer->taskId,
			seconds:     $timer->seconds,
			source:      Source::System,
			text:        '',
			createdAtTs: $startTs,
			startTs:     $startTs,
			stopTs:      $stopTs,
		);

		[, $timeSpent] = $this->elapsedTimeService->add($elapsedTime);

		if ($timer->taskId <= 0)
		{
			return $timer;
		}

		$task = $this->taskRepository->getById($timer->taskId);
		if ($task === null)
		{
			throw new TaskNotFoundException('Task not found');
		}

		$affectedUserIds = $this->getAffectedUserIds($task, $userId);

		$this->cacheService->clearByTagMulti('tasks_user', $affectedUserIds);

		$timeElapsed = [
			$userId => $timeSpent,
		];

		$timers = $this->timerRepository->getByUserIds($affectedUserIds, $timer->taskId);

		foreach ($affectedUserIds as $affectedUserId)
		{
			if ($affectedUserId === $userId)
			{
				continue;
			}

			$timeElapsed[$affectedUserId] = $timeSpent;

			$userTimer = $timers->findOneById($affectedUserId);
			if ($userTimer !== null && $userTimer->startedAtTs > 0)
			{
				$timeElapsed[$affectedUserId] += time() - $userTimer->startedAtTs;
			}
		}

		$parameters = [
			'taskId' => $timer->taskId,
			'userId' => $userId,
			'timeElapsed' => $timeElapsed,
		];

		$this->pushService->addEventByParameters(
			UserCollection::mapFromIds($affectedUserIds),
			PushCommand::TASK_TIMER_STOPPED,
			$parameters
		);

		if (!$sendNotification)
		{
			return $timer;
		}

		$this->egressController->process(
			new StopTimerCommand(
				userId: $userId,
				taskId: $timer->taskId,
				seconds: $timer->seconds,
			)
		);

		return $timer;
	}

	/**
	 * @throws TaskNotFoundException
	 */
	public function stopAllTimers(
		int $taskId,
		array $userIds,
		int $currentUserId,
		bool $sendNotification = true,
	): void
	{
		if ($taskId <= 0 || empty($userIds))
		{
			return;
		}

		$activeTimers = $this->timerRepository->getRunningTimersByTaskId($taskId);

		foreach ($userIds as $userId)
		{
			$this->stopTimer(
				userId: (int)$userId,
				taskId: $taskId,
				sendNotification : false,
			);
		}

		if (!$sendNotification || $activeTimers->isEmpty())
		{
			return;
		}

		$task = $this->taskRepository->getById($taskId);
		$triggeredBy = $this->userRepository->getByIds([$currentUserId])->findOneById($currentUserId);

		if (!$task)
		{
			return;
		}

		$this->chatNotification->notify(
			type: NotificationType::TaskTimersStopped,
			task: $task,
			args: [
				'triggeredBy' => $triggeredBy,
				'seconds' => $this->elapsedTimeProvider->getTimeSpentOnTask($taskId),
			],
		);
	}

	private function getAffectedUserIds(Task $task, int $userId): array
	{
		$responsibleId = (int)$task->responsible?->getId();
		$accomplices = $task->accomplices->getIdList();

		return array_unique(
			array_merge(
				[$userId, $responsibleId],
				$accomplices
			)
		);
	}
}

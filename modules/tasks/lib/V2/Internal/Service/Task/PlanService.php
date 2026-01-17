<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task;

use Bitrix\Main\ArgumentException;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Event\Task\OnPlanChangedEvent;
use Bitrix\Tasks\V2\Internal\EventDispatcher\EventDispatcher;
use Bitrix\Tasks\V2\Internal\Repository\TaskRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\UserRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Internal\Service\UpdateTaskService;

class PlanService
{
	public function __construct(
		private readonly UpdateTaskService $updateTaskService,
		private readonly EventDispatcher $eventDispatcher,
		private readonly TaskRepositoryInterface $taskRepository,
		private readonly UserRepositoryInterface $userRepository,
	)
	{

	}

	public function update(Entity\Task $task, UpdateConfig $config): Entity\Task
	{
		$taskBefore = $this->taskRepository->getById($task->getId());
		if ($taskBefore === null)
		{
			throw new ArgumentException();
		}

		$task = $this->updateTaskService->update(
			task: $task,
			config: $config,
		);

		$this->eventDispatcher::dispatch(new OnPlanChangedEvent(
			task: $task,
			taskBefore: $taskBefore,
			triggeredBy: $this->userRepository->getByIds([$config->getUserId()])->findOneById($config->getUserId()),
		));

		return $task;
	}
}

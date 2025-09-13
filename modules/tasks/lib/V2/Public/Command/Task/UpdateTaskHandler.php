<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task;

use Bitrix\Tasks\Control\Exception\WrongTaskIdException;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\Consistency\ConsistencyResolverInterface;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\UpdateUserFields;
use Bitrix\Tasks\V2\Internal\Repository\TaskRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Task\UpdateService;

class UpdateTaskHandler
{
	public function __construct(
		private readonly ConsistencyResolverInterface $consistencyResolver,
		private readonly UpdateService $updateService,
		private readonly TaskRepositoryInterface $taskRepository,
	)
	{

	}

	public function __invoke(UpdateTaskCommand $command): Entity\Task
	{
		if ($command->task->id <= 0)
		{
			throw new WrongTaskIdException();
		}

		[$task, $fields] = $this->consistencyResolver->resolve('task.update')->wrap(
			fn (): array => $this->updateService->update($command->task, $command->config)
		);

		// this action is outside of consistency because it is containing nested transactions
		if ((new UpdateUserFields($command->config))($fields, $command->task->getId()))
		{
			$this->taskRepository->invalidate($task->id);
		}

		return $this->taskRepository->getById($task->id);
	}
}

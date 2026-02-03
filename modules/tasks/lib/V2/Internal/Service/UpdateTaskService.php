<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service;

use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Tasks\Control\Exception\TaskNotExistsException;
use Bitrix\Tasks\Control\Exception\TaskUpdateException;
use Bitrix\Tasks\Control\Exception\WrongTaskIdException;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Repository\TaskRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Consistency\ConsistencyResolverInterface;
use Bitrix\Tasks\V2\Internal\Service\Esg\EgressController;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\RunCrm;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\RunUpdateEvent;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\UpdateUserFields;
use Bitrix\Tasks\V2\Internal\Service\Task\UpdateService;
use Bitrix\Tasks\V2\Public\Command\Task\UpdateTaskCommand;

class UpdateTaskService
{
	public function __construct(
		private readonly UpdateService $updateService,
		private readonly ConsistencyResolverInterface $consistencyResolver,
		private readonly TaskRepositoryInterface $taskRepository,
		private readonly EgressController $egressController,
	)
	{

	}

	/**
	 * @throws TaskNotExistsException
	 * @throws CommandValidationException
	 * @throws WrongTaskIdException
	 * @throws TaskUpdateException
	 */
	public function update(Task $task, UpdateConfig $config): Task
	{
		if ($task->id <= 0)
		{
			throw new WrongTaskIdException();
		}

		if ($config->isUseConsistency())
		{
			[$task, $fields, $taskBefore, $taskObjectBefore, $sourceTaskData] = $this->consistencyResolver->resolve('task.update')->wrap(
				fn (): array => $this->updateService->update($task, $config)
			);
		}
		else
		{
			[$task, $fields, $taskBefore, $taskObjectBefore, $sourceTaskData] = $this->updateService->update($task, $config);
		}

		if ((new UpdateUserFields($config))($fields, $task->id))
		{
			$this->taskRepository->invalidate($task->id);

			$task = $this->getTask($task->id);
		}

		(new RunUpdateEvent($config))(
			$fields,
			$sourceTaskData,
			static fn (mixed $event): bool => is_array($event) && ($event['TO_MODULE_ID'] ?? null) === 'crm',
		);

		(new RunCrm($config))($fields, $taskObjectBefore);

		$this->egressController->processUserFields(new UpdateTaskCommand(
			task: $task,
			config: $config,
			taskBeforeUpdate: $taskBefore,
		));

		return $this->getTask($task->id);
	}

	/**
	 * @throws TaskNotExistsException
	 */
	protected function getTask(int $taskId): Task
	{
		$task = $this->taskRepository->getById($taskId);
		if ($task === null)
		{
			throw new TaskNotExistsException();
		}

		return $task;
	}
}

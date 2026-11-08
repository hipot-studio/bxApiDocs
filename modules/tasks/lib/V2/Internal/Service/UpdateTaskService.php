<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Tasks\Control\Exception\TaskNotExistsException;
use Bitrix\Tasks\Control\Exception\TaskUpdateException;
use Bitrix\Tasks\Control\Exception\WrongTaskIdException;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Repository\TaskRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Consistency\ConsistencyResolverInterface;
use Bitrix\Tasks\V2\Internal\Service\Esg\EgressController;
use Bitrix\Tasks\V2\Internal\Service\Interaction\InteractionRecorder;
use Bitrix\Tasks\V2\Internal\Service\Ranking\EventType;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\RunBizProc;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\RunCrm;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\RunUpdateEvent;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\UpdateSearchIndex;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\UpdateUserFields;
use Bitrix\Tasks\V2\Internal\Service\Task\Mutation\TaskMutationRegistry;
use Bitrix\Tasks\V2\Internal\Service\Task\UpdateService;
use Bitrix\Tasks\V2\Public\Command\Task\UpdateTaskCommand;

class UpdateTaskService
{
	public function __construct(
		private readonly UpdateService $updateService,
		private readonly ConsistencyResolverInterface $consistencyResolver,
		private readonly TaskRepositoryInterface $taskRepository,
		private readonly EgressController $egressController,
		private readonly InteractionRecorder $interactionRecorder,
		private readonly TaskMutationRegistry $mutationRegistry,
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

		$hasMutationScope = $this->mutationRegistry->active(Application::getConnection()) !== null;
		if ($config->isUseConsistency() && !$hasMutationScope)
		{
			[
				$task,
				$fields,
				$taskBefore,
				$taskObjectBefore,
				$sourceTaskData,
				$fullTaskData,
				$searchIndexFields,
			] = $this->consistencyResolver->resolve('task.update')->wrap(
				fn (): array => $this->updateService->update(
					task: $task,
					config: $config,
					deferSearchIndex: true,
				),
			);
		}
		else
		{
			[
				$task,
				$fields,
				$taskBefore,
				$taskObjectBefore,
				$sourceTaskData,
				$fullTaskData,
				$searchIndexFields,
			] = $this->updateService->update(
				task: $task,
				config: $config,
				deferSearchIndex: true,
			);
		}

		// Outside the consistency wrapper: a rolled back update must not add weight.
		// Re-entrant updates count once - the recorder dedups within the request.
		$this->interactionRecorder->record(
			userId: $config->getUserId(),
			taskId: (int)$task->id,
			eventType: EventType::Update,
			relationKind: null,
			origin: $config->getOrigin(),
		);

		try
		{
			(new UpdateUserFields($config))($fields, $task->id);
		}
		finally
		{
			(new UpdateSearchIndex())($fullTaskData, $searchIndexFields);
			$this->taskRepository->invalidate($task->id);
		}

		(new RunUpdateEvent($config))(
			$fields,
			$sourceTaskData,
			static fn (mixed $event): bool => is_array($event) && ($event['TO_MODULE_ID'] ?? null) === 'crm',
		);

		$this->taskRepository->invalidate($task->id);

		(new RunBizProc($config))($fields, $taskObjectBefore);

		$this->taskRepository->invalidate($task->id);

		(new RunCrm($config))($fields, $taskObjectBefore);

		$this->taskRepository->invalidate($task->id);

		$task = $this->taskRepository->getById($task->id);

		if ($task === null)
		{
			throw new TaskNotExistsException();
		}

		$this->egressController->processUserFields(new UpdateTaskCommand(
			task: $task,
			config: $config,
			taskBeforeUpdate: $taskBefore,
		));

		return $task;
	}
}

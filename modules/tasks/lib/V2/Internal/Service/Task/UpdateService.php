<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task;

use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Tasks\Control\Exception\TaskNotExistsException;
use Bitrix\Tasks\Control\Exception\TaskUpdateException;
use Bitrix\Tasks\Internals\TaskObject;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\Internals\Task\Status;
use Bitrix\Tasks\V2\Internal\Repository\DeadlineChangeLogRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Counter;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\AttachDependence;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\CorrectDatePlan;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\RunInternalEvent;
use Bitrix\Tasks\V2\Public\Command\Task\UpdateTaskCommand;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\AutoClose;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\CleanCache;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\CloseResult;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Pin;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\RunIntegration;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\RunUpdateEvent;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\SendNotification;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\SendPush;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\StopTimer;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\UpdateRelatedTasks;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\UpdateHistoryLog;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\UpdateLegacyFiles;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\UpdateMembers;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\UpdateParameters;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\UpdateSearchIndex;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\UpdateSync;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\UpdateTags;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\UpdateUserOptions;
use Bitrix\Tasks\V2\Internal\Repository\TaskRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Esg\EgressInterface;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\Task\Prepare\Update\EntityFieldService;
use CTaskLog;

class UpdateService
{
	public function __construct(
		private readonly TaskRepositoryInterface $repository,
		private readonly DeadlineChangeLogRepositoryInterface $deadlineChangeLogRepository,
		private readonly EgressInterface $egressController,
		private readonly ValidationService $validationService,
		private readonly Counter\Service $counterService,
	)
	{

	}

	/**
	 * @throws TaskNotExistsException
	 * @throws CommandValidationException
	 * @throws TaskUpdateException
	 */
	public function update(
		Entity\Task $task,
		UpdateConfig $config,
	): array
	{
		$this->loadMessages();

		$entityBefore = $this->repository->getById($task->getId());
		if ($entityBefore === null)
		{
			throw new TaskNotExistsException();
		}

		// we do validation here, because we need merge states and get new entity to check
		$this->validate($entityBefore, $task);

		$taskChangesContext = clone $task;

		$compatibilityRepository = Container::getInstance()->getTaskCompatabilityRepository();
		$fullTaskData = $compatibilityRepository->getTaskData($task->getId());

		/**
		 * @var Entity\Task $task
		 * @var array $fields
		 */
		[$task, $fields] = (new EntityFieldService())->prepare($task, $config, $fullTaskData);

		$taskObjectBeforeUpdate = $compatibilityRepository->getTaskObject($task->getId());
		$taskObjectBeforeUpdate->cacheCrmFields();

		$this->counterService->collect($task->getId());

		$id = $this->repository->save($task);

		$fields['ID'] = $id;

		$changes = $this->getChanges($fields, $fullTaskData);

		$updateMemberService = new UpdateMembers($config);
		$updateMemberInfo = $updateMemberService($fields, $fullTaskData, $changes);

		(new UpdateParameters($config))($fields, $fullTaskData);

		(new UpdateLegacyFiles($config))($fields, $fullTaskData, $changes);

		(new UpdateTags($config))($fields, $fullTaskData, $changes);

		(new UpdateRelatedTasks($config))($fields, $fullTaskData);

		(new AttachDependence($config))($fields, $fullTaskData);

		(new CorrectDatePlan($config))($fields, $fullTaskData);

		/**
		 * @var array $sourceTaskData
		 * @var array $fullTaskData
		 * @var TaskObject $taskObject
		 * @var array $fields
		 */
		[$sourceTaskData, $fullTaskData, $taskObject, $fields] = $this->reload($fields, $fullTaskData);

		(new UpdateHistoryLog($config))($fullTaskData, $changes);

		(new AutoClose($config))($fields, $fullTaskData);

		(new SendNotification($config))($fields, $fullTaskData, $sourceTaskData, $taskObject);

		(new UpdateSearchIndex())($fullTaskData, $fields);

		(new UpdateSync())($fields, $sourceTaskData);

		$fields = (new RunUpdateEvent($config))(
			$fields,
			$sourceTaskData,
			static fn (mixed $event): bool => is_array($event) && ($event['TO_MODULE_ID'] ?? null) !== 'crm',
		);

		(new CleanCache($config))($fullTaskData);

		(new UpdateUserOptions())($fields, $sourceTaskData);

		if (!$config->isSkipRecount())
		{
			$this->counterService->send(new Counter\Command\AfterTaskUpdate(
				oldRecord: $sourceTaskData,
				newRecord: $fullTaskData,
				params: $config->getByPassParameters(),
			));
		}

		(new CloseResult($config))($fullTaskData);

		(new Pin())($fullTaskData, $sourceTaskData);

		(new SendPush($config))($fullTaskData, $sourceTaskData, $changes);

		(new StopTimer($config))($fullTaskData, $changes);

		// get task object with prepopulated data
		$this->repository->invalidate($taskObject->getId());

		$taskAfterUpdate = $this->repository->getById($taskObject->getId());

		if ($taskAfterUpdate === null)
		{
			throw new TaskNotExistsException();
		}

		// todo Delete after the deadline changes from all places through the new api.
		$deadlineChangeReason = ($fields['DEADLINE_CHANGE_REASON'] ?? null);
		$taskAfterUpdate->deadlineChangeReason = $deadlineChangeReason;

		(new RunIntegration())($taskAfterUpdate);

		if (isset($fields['DEADLINE']))
		{
			$deadlineDateTime = $fields['DEADLINE'];
			if (!($deadlineDateTime instanceof DateTime))
			{
				$deadlineDateTime = null;
			}

			$this->deadlineChangeLogRepository->append(
				taskId: $task->getId(),
				userId: $fields['CHANGED_BY'],
				dateTime: $deadlineDateTime,
				reason: $deadlineChangeReason,
			);
		}

		// notify external services about updated task
		$this->egressController->process(new UpdateTaskCommand(
			task: $taskAfterUpdate,
			config: $config,
			taskBeforeUpdate: $entityBefore,
			taskChangesContext: $taskChangesContext,
		));

		(new RunInternalEvent())($entityBefore, $taskAfterUpdate);

		if ($task->responsible || $entityBefore->responsible->id !== $taskAfterUpdate->responsible->id)
		{
			$context = \Bitrix\Main\Context::getCurrent();
			Container::getInstance()
				->getLogger()
				->logWarning(
					[
						'targetUserId' => $task->responsible?->id,
						'taskId' => $task->id,
						'requestStartTime' => $context?->getServer()->get('REQUEST_TIME_FLOAT'),
						'action' => $context?->getRequest()->get('action'),
						'responsibleBefore' => $entityBefore->responsible->id,
						'responsibleAfter' => $taskAfterUpdate->responsible->id,
						'members' => $updateMemberInfo,
						'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS),
					],
					'TASKS_UPDATE_RESPONSIBLE_DEBUG'
				);
		}

		return [$taskAfterUpdate, $fields, $entityBefore, $taskObjectBeforeUpdate, $sourceTaskData];
	}

	private function getChanges(array $fields, array $fullTaskData): array
	{
		if (isset($fullTaskData['DURATION_PLAN']))
		{
			unset($fullTaskData['DURATION_PLAN']);
		}

		if (isset($fields['DURATION_PLAN']))
		{
			// at this point, $arFields['DURATION_PLAN'] in seconds
			$fields['DURATION_PLAN_SECONDS'] = $fields['DURATION_PLAN'];
			unset($fields['DURATION_PLAN']);
		}

		return CTaskLog::GetChanges($fullTaskData, $fields);
	}

	/**
	 * @throws TaskNotExistsException
	 */
	private function reload(array $fields, array $fullTaskData): array
	{
		$compatibilityRepository = Container::getInstance()->getTaskCompatabilityRepository();

		$sourceTaskData = $fullTaskData;

		$fullTaskData = $compatibilityRepository->getTaskData($fullTaskData['ID']);

		$currentStatus = (int)$fullTaskData['REAL_STATUS'];
		$prevStatus = (int)$sourceTaskData['REAL_STATUS'];
		$statusChanged =
			$currentStatus !== $prevStatus
			&& $currentStatus >= Status::NEW
			&& $currentStatus <= Status::DECLINED;

		if ($statusChanged)
		{
			$fullTaskData['STATUS_CHANGED'] = true;

			if ($currentStatus === Status::DECLINED)
			{
				$fullTaskData['DECLINE_REASON'] = $fields['DECLINE_REASON'];
			}
		}

		$fields['ID'] = $fullTaskData['ID'];

		$task = $compatibilityRepository->getTaskObject($fullTaskData['ID']);

		$scenarioObject = $task->getScenario();

		$fields['SCENARIO'] = is_null($scenarioObject)
			? Entity\Task\Scenario::Default->value
			: $scenarioObject->getScenario();

		return [$sourceTaskData, $fullTaskData, $task, $fields];
	}

	private function validate(Entity\Task $entityBefore, Entity\Task $entityAfter): void
	{
		$props = array_filter($entityAfter->toArray());

		$validationResult = $this->validationService->validate($entityBefore->cloneWith($props));
		if (!$validationResult->isSuccess())
		{
			Container::getInstance()->getLogger()->logValidationErrorWarning($validationResult->getErrorCollection());

			throw new CommandValidationException($validationResult->getErrors());
		}
	}

	private function loadMessages(): void
	{
		Loc::loadMessages($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/tasks/lib/control/task.php');
		Loc::loadMessages($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/tasks/lib/control/handler/taskfieldhandler.php');
	}
}

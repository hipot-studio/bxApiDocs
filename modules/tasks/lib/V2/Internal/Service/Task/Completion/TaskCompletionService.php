<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Completion;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Tasks\V2\Internal\Entity\Task\Status;
use Bitrix\Tasks\V2\Internal\Access\Task\ActionDictionary;
use Bitrix\Tasks\V2\Internal\Service\Task\StatusService;
use Bitrix\Tasks\V2\Internal\Access\Service\TaskAccessService;
use Bitrix\Tasks\V2\Internal\Access\Task\Status\CompletionAccessRule;
use Bitrix\Tasks\V2\Internal\Repository\TaskStatusRepository;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;

class TaskCompletionService
{
	public function __construct(
		private readonly CheckListCompletionService $checkListCompletionService,
		private readonly TaskStatusRepository $taskStatusRepository,
		private readonly TaskAccessService $taskAccessService,
		private readonly StatusService $statusService,
	)
	{
	}

	public function complete(
		int $taskId,
		UpdateConfig $config,
		bool $withCheckList = true,
		?CompletionConfig $completionConfig = null,
	): Result
	{
		$completionConfig ??= new CompletionConfig();
		$result = new Result();
		$task = $this->taskStatusRepository->getById($taskId);

		if ($task === null)
		{
			return $result->addError(new Error('Task not found', 'TASK_NOT_FOUND'));
		}

		$userId = $config->getUserId();
		$isFinalStatus = $task->status?->isCompleted() === true;
		if (!$isFinalStatus && !$this->canCompleteWithChecklist($taskId, $userId))
		{
			$isFinalStatus = $this->hasFinalStatus($taskId);
			if (!$isFinalStatus)
			{
				return $result->addError($this->getAccessError());
			}
		}

		if ($isFinalStatus)
		{
			if (!$this->taskAccessService->can($userId, ActionDictionary::TASK_ACTIONS['read'], $taskId))
			{
				return $result->addError($this->getAccessError());
			}

			if (!$completionConfig->completeCheckListForFinalTask)
			{
				return $result;
			}
		}

		if ($withCheckList)
		{
			$checkListResult = $this->checkListCompletionService->completeForTask(
				taskId: $taskId,
				userId: $userId,
				useBatch: $completionConfig->useBatchCheckListCompletion,
			);
			if (!$checkListResult->isSuccess())
			{
				return $result->addErrors($checkListResult->getErrors());
			}
		}

		if ($isFinalStatus)
		{
			return $result;
		}

		$commandResult = $this->runCompleteTaskCommand($taskId, $config);
		if (!$commandResult->isSuccess())
		{
			return $result->addErrors($commandResult->getErrors());
		}

		return $result;
	}

	public function approve(int $taskId, UpdateConfig $config): Result
	{
		$result = new Result();
		$task = $this->taskStatusRepository->getById($taskId);
		$userId = $config->getUserId();
		if ($task === null)
		{
			return $result->addError(new Error('Task not found', 'TASK_NOT_FOUND'));
		}

		if (!$this->taskAccessService->can($userId, ActionDictionary::TASK_ACTIONS['read'], $taskId))
		{
			return $result->addError($this->getAccessError());
		}

		if ($task->status === Status::Completed)
		{
			return $result;
		}

		if (
			$task->status !== Status::SupposedlyCompleted
			|| !$this->taskAccessService->can($userId, ActionDictionary::TASK_ACTIONS['approve'], $taskId)
		)
		{
			return $result->addError($this->getAccessError());
		}

		$commandResult = $this->runApproveTaskCommand($taskId, $config);

		return $commandResult->isSuccess() ? $result : $result->addErrors($commandResult->getErrors());
	}

	protected function runApproveTaskCommand(int $taskId, UpdateConfig $config): Result
	{
		$result = new Result();
		try
		{
			$this->statusService->approve($taskId, $config);

			return $result;
		}
		catch (\Throwable $exception)
		{
			return $result->addError(Error::createFromThrowable($exception));
		}
	}

	public function canCompleteWithChecklist(int $taskId, int $userId): bool
	{
		return CompletionAccessRule::isSatisfiedBy(
			fn (string $action): bool => $this->taskAccessService->can($userId, $action, $taskId),
		);
	}

	public function hasFinalStatus(int $taskId): bool
	{
		return $this->taskStatusRepository->getById($taskId)?->status?->isCompleted() === true;
	}

	protected function runCompleteTaskCommand(int $taskId, UpdateConfig $config): Result
	{
		$result = new Result();
		try
		{
			$this->statusService->complete($taskId, $config);

			return $result;
		}
		catch (\Throwable $exception)
		{
			return $result->addError(Error::createFromThrowable($exception));
		}
	}

	private function getAccessError(): Error
	{
		return $this->taskAccessService->getUserError() ?? new Error('Access denied', 'ACCESS_DENIED');
	}
}

<?php

namespace Bitrix\Tasks\V2\Public\Provider;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\Model\UserModel;
use Bitrix\Tasks\Access\TaskAccessController;
use Bitrix\Tasks\Deadline\Policy\DeadlinePolicy;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Repository\DeadlineChangeLogRepositoryInterface;

class DeadlineProvider
{
	public function __construct(
		private readonly DeadlineChangeLogRepositoryInterface $deadlineChangeLogRepository,
	)
	{
	}

	public function getDeadlineChangeCount(int $userId, int $taskId): int
	{
		return $this->deadlineChangeLogRepository->countUserChanges($userId, $taskId);
	}

	public function canChangeDeadline(int $userId, Task $task): Result
	{
		$result = new Result();

		$isCreator = $task->creator->getId() === $userId;
		$user = UserModel::createFromId($userId);
		$canEdit = TaskAccessController::can($userId, ActionDictionary::ACTION_TASK_EDIT, $task->getId());

		$canChangeDeadline = ($isCreator || $user->isAdmin() || $canEdit);

		$deadLinePolicy = new DeadlinePolicy(
			canChangeDeadline: $canChangeDeadline,
			dateTime: $task->maxDeadlineChangeDate,
			maxDeadlineChanges: $task->maxDeadlineChanges,
			requireDeadlineChangeReason: $task->requireDeadlineChangeReason,
		);

		$deadlineChangeCount = $this->getDeadlineChangeCount($userId, $task->getId());

		[$allowed, $violations] = $deadLinePolicy->canUpdateDeadline(
			dateTime: DateTime::createFromTimestamp($task->deadlineTs),
			userChangesCount: $deadlineChangeCount,
			reason: $task->deadlineChangeReason,
		);

		if (!$allowed)
		{
			$result->addError(new Error('Cannot change deadline: ' . implode(', ', $violations)));

			return $result;
		}

		return $result;
	}
}

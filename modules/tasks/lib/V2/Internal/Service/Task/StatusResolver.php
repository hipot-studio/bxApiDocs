<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task;

use Bitrix\Tasks\Control\Exception\TaskNotExistsException;
use Bitrix\Tasks\V2\Internal\Entity\Task\Status;
use Bitrix\Tasks\V2\Internal\Repository\TaskRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\UserRoleCheckerInterface;

class StatusResolver
{
	public function __construct(
		private readonly TaskRepositoryInterface $taskRepository,
		private readonly UserRoleCheckerInterface $userRoleChecker,
	)
	{

	}

	public function resolveForComplete(int $taskId, int $userId): Status
	{
		$task = $this->taskRepository->getById($taskId);
		if ($task === null)
		{
			throw new TaskNotExistsException();
		}

		$creatorId = $task->getCreatorId();
		$responsibleId = $task->responsible?->getId();

		if (
			!$task->needsControl
			|| $creatorId === $responsibleId
			|| $creatorId === $userId
		)
		{
			return Status::Completed;
		}

		// Mapper leaves creator null when the profile is gone; creatorId still holds the ghost id.
		// Without a living creator, admin finishes TASK_CONTROL in one step, not only from SupposedlyCompleted.
		if ($task->creator === null && $this->userRoleChecker->isSuper($userId))
		{
			return Status::Completed;
		}

		if ($task->status === Status::SupposedlyCompleted)
		{
			if ($this->userRoleChecker->isSuper($userId))
			{
				return Status::Completed;
			}

			if ($creatorId > 0 && $this->userRoleChecker->isBoss($creatorId, $userId))
			{
				return Status::Completed;
			}
		}

		return Status::SupposedlyCompleted;
	}
}

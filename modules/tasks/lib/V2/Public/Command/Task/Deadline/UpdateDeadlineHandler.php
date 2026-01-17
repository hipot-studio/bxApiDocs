<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Deadline;

use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Main\ObjectException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Control\Exception\TaskNotExistsException;
use Bitrix\Tasks\Control\Exception\TaskUpdateException;
use Bitrix\Tasks\Control\Exception\WrongTaskIdException;
use Bitrix\Tasks\Deadline\Policy\DeadlinePolicy;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Exception\Task\UpdateDeadlineException;
use Bitrix\Tasks\V2\Internal\Repository\DeadlineChangeLogRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\UpdateTaskService;

class UpdateDeadlineHandler
{
	public function __construct(
		private readonly UpdateTaskService $updateService,
		private readonly DeadlinePolicy $deadlinePolicy,
		private readonly DeadlineChangeLogRepositoryInterface $deadlineChangeLogRepository,
	)
	{

	}

	/**
	 * @throws TaskNotExistsException
	 * @throws CommandValidationException
	 * @throws UpdateDeadlineException
	 * @throws ObjectException
	 * @throws WrongTaskIdException
	 * @throws TaskUpdateException
	 */
	public function __invoke(UpdateDeadlineCommand $updateDeadlineCommand): Entity\Task
	{
		$entity = new Entity\Task(
			id: $updateDeadlineCommand->taskId,
			deadlineTs: $updateDeadlineCommand->deadlineTs,
			deadlineChangeReason: $updateDeadlineCommand->reason,
		);

		$userChangesCount = $this->deadlineChangeLogRepository
			->countUserChanges($updateDeadlineCommand->updateConfig->getUserId(), $entity->getId())
		;

		[$allowed, $violations] = $this->deadlinePolicy->canUpdateDeadline(
			dateTime: DateTime::createFromTimestamp($updateDeadlineCommand->deadlineTs),
			userChangesCount: $userChangesCount,
			reason: $updateDeadlineCommand->reason
		);

		if (!$allowed)
		{
			throw new UpdateDeadlineException("Cannot update deadline: " . implode(', ', $violations));
		}

		$task = $this->updateService->update($entity, $updateDeadlineCommand->updateConfig);

		/* todo Recover after the deadline changes from all places through the new api.
		$this->deadlineChangeLogRepository->append(
			taskId: $entity->getId(),
			userId: $updateDeadlineCommand->updateConfig->getUserId(),
			dateTime: DateTime::createFromTimestamp($updateDeadlineCommand->deadlineTs),
			reason: $updateDeadlineCommand->reason,
		);
		*/

		return $task;
	}
}

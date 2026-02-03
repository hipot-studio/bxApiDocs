<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service;

use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Tasks\Control\Exception\TaskAddException;
use Bitrix\Tasks\Control\Exception\TaskNotExistsException;
use Bitrix\Tasks\Control\Exception\TaskStopDeleteException;
use Bitrix\Tasks\Control\Exception\TaskUpdateException;
use Bitrix\Tasks\Control\Exception\WrongTaskIdException;
use Bitrix\Tasks\V2\Internal\Access\Service\TaskAccessService;
use Bitrix\Tasks\V2\Internal\Entity\Group;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Entity\UserCollection;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\AccessDeniedException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\InvalidIdentifierException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\NotFoundException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\CreateTaskDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\DeleteTaskDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\MakeTaskRecurringDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\UpdateTaskDto;
use Bitrix\Tasks\V2\Internal\Service\AddTaskService;
use Bitrix\Tasks\V2\Internal\Service\DeleteTaskService;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Config\AddConfig;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Delete\Config\DeleteConfig;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Internal\Service\UpdateTaskService;

class TaskService
{
	public function __construct(
		private readonly TaskAccessService $accessService,
		private readonly AddTaskService $addService,
		private readonly UpdateTaskService $updateService,
		private readonly DeleteTaskService $deleteService,
	)
	{
	}

	/**
	 * @throws AccessDeniedException
	 * @throws TaskAddException
	 * @throws TaskNotExistsException
	 */
	public function create(CreateTaskDto $dto, int $userId): Task
	{
		$task = new Task(
			title: $dto->title,
			description: $dto->description,
			creator: new User($dto->creatorId),
			responsible: new User($dto->responsibleId),
			deadlineTs: $dto->deadlineTs,
			group: $dto->groupId !== null ? new Group($dto->groupId) : null,
			priority: $dto->priority,
			status: $dto->status,
			accomplices: $dto->accompliceIds !== null ? UserCollection::mapFromIds($dto->accompliceIds) : null,
			auditors: $dto->auditorIds !== null ? UserCollection::mapFromIds($dto->auditorIds) : null,
			parent: $dto->parentId !== null ? new Task($dto->parentId) : null,
		);

		if (!$this->accessService->canSave($userId, $task))
		{
			throw new AccessDeniedException();
		}

		$config = new AddConfig($userId);

		return $this->addService->add($task, $config);
	}

	/**
	 * @throws AccessDeniedException
	 * @throws CommandValidationException
	 * @throws InvalidIdentifierException
	 * @throws NotFoundException
	 * @throws TaskUpdateException
	 * @throws WrongTaskIdException
	 */
	public function update(UpdateTaskDto $dto, int $userId): void
	{
		if ($dto->taskId <= 0)
		{
			throw new InvalidIdentifierException();
		}

		$task = new Task(
			id: $dto->taskId,
			title: $dto->title,
			description: $dto->description,
			creator: $dto->creatorId !== null ? new User($dto->creatorId) : null,
			responsible: $dto->responsibleId !== null ? new User($dto->responsibleId) : null,
			deadlineTs: $dto->deadlineTs,
			group: $dto->groupId !== null ? new Group($dto->groupId) : null,
			priority: $dto->priority,
			status: $dto->status,
			parent: $dto->parentId !== null ? new Task($dto->parentId) : null,
		);

		if (!$this->accessService->canSave($userId, $task))
		{
			throw new AccessDeniedException();
		}

		$config = new UpdateConfig($userId);

		try
		{
			$this->updateService->update($task, $config);
		}
		catch (TaskNotExistsException)
		{
			throw new NotFoundException();
		}
	}

	/**
	 * @throws AccessDeniedException
	 * @throws InvalidIdentifierException
	 * @throws NotFoundException
	 * @throws TaskStopDeleteException
	 * @throws WrongTaskIdException
	 */
	public function delete(DeleteTaskDto $dto, int $userId): void
	{
		if ($dto->taskId <= 0)
		{
			throw new InvalidIdentifierException();
		}

		if (!$this->accessService->canDelete($userId, $dto->taskId))
		{
			throw new AccessDeniedException();
		}

		$config = new DeleteConfig($userId);

		try
		{
			$this->deleteService->delete($dto->taskId, $config);
		}
		catch (TaskNotExistsException)
		{
			throw new NotFoundException();
		}
	}

	/**
	 * @throws AccessDeniedException
	 * @throws CommandValidationException
	 * @throws InvalidIdentifierException
	 * @throws NotFoundException
	 * @throws TaskUpdateException
	 * @throws WrongTaskIdException
	 */
	public function markAsRecurring(MakeTaskRecurringDto $dto, int $userId): void
	{
		if ($dto->taskId <= 0)
		{
			throw new InvalidIdentifierException();
		}

		if (!$this->accessService->canUpdate($userId, $dto->taskId))
		{
			throw new AccessDeniedException();
		}

		$task = new Task(id: $dto->taskId, replicate: true);

		$config = new UpdateConfig($userId);

		try
		{
			$this->updateService->update($task, $config);
		}
		catch (TaskNotExistsException)
		{
			throw new NotFoundException();
		}
	}
}

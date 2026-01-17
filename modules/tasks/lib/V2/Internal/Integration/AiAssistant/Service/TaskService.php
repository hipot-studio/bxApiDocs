<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service;

use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Control\Exception\TaskAddException;
use Bitrix\Tasks\Control\Exception\TaskNotExistsException;
use Bitrix\Tasks\Control\Exception\TaskStopDeleteException;
use Bitrix\Tasks\Control\Exception\TaskUpdateException;
use Bitrix\Tasks\Control\Exception\WrongTaskIdException;
use Bitrix\Tasks\Internals\Task\Status;
use Bitrix\Tasks\Provider\Exception\TaskListException;
use Bitrix\Tasks\Provider\Query\TaskQuery;
use Bitrix\Tasks\Provider\TaskList;
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
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\GetTaskByIdDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\MakeTaskRecurringDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\SearchTasksDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\UpdateTaskDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder\BaseSchemaBuilder;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\TaskStatusMapper;
use Bitrix\Tasks\V2\Internal\Repository\TaskRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\AddTaskService;
use Bitrix\Tasks\V2\Internal\Service\DeleteTaskService;
use Bitrix\Tasks\V2\Internal\Service\Link\LinkService;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Config\AddConfig;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Delete\Config\DeleteConfig;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Internal\Service\UpdateTaskService;

class TaskService
{
	protected const TASKS_LIMIT = 15;

	public function __construct(
		private readonly TaskAccessService $accessService,
		private readonly AddTaskService $addService,
		private readonly UpdateTaskService $updateService,
		private readonly DeleteTaskService $deleteService,
		private readonly TaskRepositoryInterface $taskRepository,
		private readonly TaskStatusMapper $statusMapper,
		private readonly TaskList $taskProvider,
		private readonly LinkService $linkService,
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
		$prepareMembersCallback = static fn (int $id): User => new User($id);

		$accomplices =
			$dto->accompliceIds !== null
				? new UserCollection(...array_map($prepareMembersCallback, $dto->accompliceIds))
				: null
		;

		$auditors =
			$dto->auditorIds !== null
				? new UserCollection(...array_map($prepareMembersCallback, $dto->auditorIds))
				: null
		;

		$task = new Task(
			title: $dto->title,
			description: $dto->description,
			creator: new User($dto->creatorId),
			responsible: new User($dto->responsibleId),
			deadlineTs: $dto->deadlineTs,
			group: $dto->groupId !== null ? new Group($dto->groupId) : null,
			priority: $dto->priority,
			status: $dto->status,
			accomplices: $accomplices,
			auditors: $auditors,
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

	/**
	 * @throws TaskListException
	 */
	public function search(SearchTasksDto $dto, int $userId): array
	{
		$query = $this->getQuery($dto, $userId);

		$tasks = $this->taskProvider->getList($query);

		$tasks = $this->prepareDeadline($tasks);

		return $this->prepareLinks($tasks, $userId);
	}

	/**
	 * @throws AccessDeniedException
	 * @throws InvalidIdentifierException
	 */
	public function getById(GetTaskByIdDto $dto, int $userId): ?array
	{
		if ($dto->taskId <= 0)
		{
			throw new InvalidIdentifierException();
		}

		if (!$this->accessService->canRead($userId, $dto->taskId))
		{
			throw new AccessDeniedException();
		}

		$task = $this->taskRepository->getById($dto->taskId);

		if ($task === null)
		{
			return null;
		}

		$deadline = $task->deadlineTs ? DateTime::createFromTimestamp($task->deadlineTs) : null;

		return [
			'id' => $task->id,
			'title' => $task->title,
			'description' => $task->description,
			'creatorId' => $task->creator?->id,
			'responsibleId' => $task->responsible?->id,
			'deadline' => $deadline?->format(BaseSchemaBuilder::DATE_FORMAT),
			'checklist' => $task->checklist,
			'groupId' => $task->group?->id,
			'priority' => $task->priority?->value,
			'status' => $task->status?->value,
			'parentId' => $task->parent?->id,
			'link' => $this->getLink($task->id, $userId),
		];
	}

	private function getQuery(SearchTasksDto $dto, int $userId): TaskQuery
	{
		$query = (new TaskQuery($userId))->setSelect(['ID', 'TITLE', 'DEADLINE']);

		if ($dto->title !== null)
		{
			$query->addWhere('%TITLE', $dto->title);
		}

		if ($dto->description !== null)
		{
			$query->addWhere('%DESCRIPTION', $dto->description);
		}

		if ($dto->deadlineFrom !== null)
		{
			$query->addWhere('>=DEADLINE', $dto->deadlineFrom);
		}

		if ($dto->deadlineTo !== null)
		{
			$query->addWhere('<=DEADLINE', $dto->deadlineTo);
		}

		if ($dto->groupId !== null)
		{
			$query->addWhere('GROUP_ID', $dto->groupId);
		}

		if ($dto->responsibleId !== null)
		{
			$query->addWhere('RESPONSIBLE_ID', $dto->responsibleId);
		}

		if ($dto->creatorId !== null)
		{
			$query->addWhere('CREATED_BY', $dto->creatorId);
		}

		if ($dto->tag !== null)
		{
			$query->addWhere('TAG.NAME', $dto->tag);
		}

		if ($dto->memberId === null && $dto->accompliceId === null && $dto->auditorId === null)
		{
			$query->addWhere('MEMBER', $userId);
		}
		else
		{
			if ($dto->memberId !== null)
			{
				$query->addWhere('MEMBER', $dto->memberId);
			}

			if ($dto->accompliceId !== null)
			{
				$query->addWhere('ACCOMPLICE', $dto->accompliceId);
			}

			if ($dto->auditorId !== null)
			{
				$query->addWhere('AUDITOR', $dto->auditorId);
			}
		}

		if ($dto->status === null)
		{
			$query->addWhere('@REAL_STATUS', Status::getInWorkStatuses());
		}
		else
		{
			$query->addWhere('REAL_STATUS', $this->statusMapper->mapFromEnum($dto->status));
		}

		$query
			->setLimit(static::TASKS_LIMIT)
			->setOrder(['DEADLINE' => 'DESC', 'ID' => 'DESC'])
		;

		return $query;
	}

	private function prepareDeadline(array $tasks): array
	{
		foreach ($tasks as $key => $task)
		{
			$deadline = $task['DEADLINE'] ?? null;

			if (!$deadline instanceof DateTime)
			{
				continue;
			}

			$tasks[$key]['DEADLINE'] = $deadline->format(BaseSchemaBuilder::DATE_FORMAT);
		}

		return $tasks;
	}

	private function prepareLinks(array $tasks, int $userId): array
	{
		foreach ($tasks as $key => $task)
		{
			$taskId = (int)($task['ID'] ?? 0);

			$tasks[$key]['LINK'] = $this->getLink($taskId, $userId);
		}

		return $tasks;
	}

	private function getLink(int $taskId, int $userId): string
	{
		return $this->linkService->get(new Task($taskId), $userId);
	}
}

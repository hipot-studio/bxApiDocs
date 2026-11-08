<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper;

use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\V2\Internal\Entity\Group;
use Bitrix\Tasks\V2\Internal\Entity\GroupCollection;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\TaskCollection;
use Bitrix\Tasks\V2\Internal\Entity\UserCollection;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Repository\TaskParameterRepositoryInterface;

class RelationTaskMapper
{
	public function __construct(
		private readonly TaskStatusMapper $taskStatusMapper,
		private readonly TaskParameterRepositoryInterface $taskParameterRepository,
	)
	{

	}

	public function mapToEntity(
		array $task,
		?User $responsible = null,
		?User $creator = null,
		?Group $group = null,
		?array $rights = null,
		?array $subTaskIds = null,
		?int $deadlineChangeCount = null,
	): Task
	{
		$taskId = (int)($task['ID'] ?? 0);

		return new Task(
			id: $taskId,
			title: $task['TITLE'] ?? '',
			responsible: $responsible,
			creator: $creator,
			deadlineTs: ($task['DEADLINE'] ?? null) instanceof DateTime ? $task['DEADLINE']->getTimestamp() : null,
			group: $group,
			status: $this->taskStatusMapper->mapToEnum((int)($task['STATUS'] ?? 0)),
			subTaskIds: $subTaskIds,
			changedTs: ($task['CHANGED_DATE'] ?? null) instanceof DateTime ? $task['CHANGED_DATE']->getTimestamp() : null,
			activityTs: ($task['ACTIVITY_DATE'] ?? null) instanceof DateTime ? $task['ACTIVITY_DATE']->getTimestamp() : null,
			allowsChangeDeadline: ($task['ALLOW_CHANGE_DEADLINE'] ?? 'N') === 'Y',
			rights: $rights,
			maxDeadlineChangeDate: $this->taskParameterRepository->maxDeadlineChangeDate($taskId),
			maxDeadlineChanges: $this->taskParameterRepository->maxDeadlineChanges($taskId),
			requireDeadlineChangeReason: $this->taskParameterRepository->requireDeadlineChangeReason($taskId),
			deadlineChangeCount: $deadlineChangeCount,
		);
	}

	public function mapToCollection(
		array $tasks,
		?UserCollection $users = null,
		?GroupCollection $groups = null,
		?array $rights = null,
		?array $subTaskIds = null,
		?array $deadlineChangeCounts = null,
	): TaskCollection
	{
		$entities = [];

		foreach ($tasks as $task)
		{
			$taskId = (int)($task['ID'] ?? 0);
			$creatorId = (int)($task['CREATED_BY'] ?? 0);

			$entities[]= $this->mapToEntity(
				task: $task,
				responsible: $users?->findOneById((int)($task['RESPONSIBLE_ID'] ?? 0)),
				// SC-005: the frontend needs the creator in relation lists only as an identifier (creator.id).
				// We return a trimmed-down user (id only) so as not to expose email/externalAuthId and other author fields.
				creator: $creatorId > 0 ? new User(id: $creatorId) : null,
				group: $groups?->findOneById((int)($task['GROUP_ID'] ?? 0)),
				rights: $rights[$taskId] ?? null,
				subTaskIds: $subTaskIds[$taskId] ?? null,
				deadlineChangeCount: $deadlineChangeCounts[$taskId] ?? null,
			);
		}

		return new TaskCollection(...$entities);
	}

	public function mapSubTaskIdsCollection(
		array $ids,
		array $subTaskIds
	): TaskCollection
	{
		$entities = [];

		foreach ($ids as $id)
		{
			$entities[]= new Task(
				id: $id,
				subTaskIds: $subTaskIds[$id] ?? null,
			);
		}

		return new TaskCollection(...$entities);
	}
}

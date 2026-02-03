<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\Mapper;

use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\V2\Internal\Entity\CheckList;
use Bitrix\Tasks\V2\Internal\Entity\CheckList\CheckListItem;
use Bitrix\Tasks\V2\Internal\Entity\Group;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\PriorityMapper;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\TaskStatusMapper;
use Bitrix\Tasks\V2\Internal\Service\Link\LinkService;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder\BaseSchemaBuilder;
use Bitrix\Tasks\V2\Internal\Service\NameService;

class TaskResponseMapper
{
	public function __construct(
		private readonly TaskStatusMapper $statusMapper,
		private readonly PriorityMapper $priorityMapper,
		private readonly NameService $nameService,
		private readonly LinkService $linkService,
	)
	{
	}

	public function mapFromArray(array $taskData, CheckList $checkList, int $userId): array
	{
		$taskId = (int)($taskData['ID'] ?? 0);

		$task = new Task(
			id: $taskId,
			title: $taskData['TITLE'] ?? null,
			description: $taskData['DESCRIPTION'] ?? null,
			creator: $this->mapUserFromArray((int)($taskData['CREATED_BY'] ?? 0), $taskData['CREATED_BY_NAME'] ?? null, $taskData['CREATED_BY_LAST_NAME'] ?? null),
			responsible: $this->mapUserFromArray((int)($taskData['RESPONSIBLE_ID'] ?? 0), $taskData['RESPONSIBLE_NAME'] ?? null, $taskData['RESPONSIBLE_LAST_NAME'] ?? null),
			deadlineTs: $this->mapDeadline($taskData['DEADLINE'] ?? null),
			group: $this->mapGroupFromArray((int)($taskData['GROUP_ID'] ?? 0), $taskData['GROUP_NAME'] ?? null, $taskData['GROUP_TYPE'] ?? null),
			priority: $this->priorityMapper->mapToEnum((int)($taskData['PRIORITY'] ?? 0)),
			status: $this->statusMapper->mapToEnum((int)($taskData['STATUS'] ?? 0)),
			parent: $this->mapTask((int)($taskData['PARENT_ID'] ?? 0)),
		);

		return $this->mapFromEntity($task, $checkList, $userId);
	}

	public function mapFromEntity(Task $task, CheckList $checkList, int $userId): array
	{
		$deadline = $task->deadlineTs ? DateTime::createFromTimestamp($task->deadlineTs) : null;

		return [
			'taskId' => $task->id,
			'title' => $task->title,
			'description' => $task->description,
			'creator' => $this->mapUserFromEntity($task->creator),
			'responsible' => $this->mapUserFromEntity($task->responsible),
			'deadline' => $deadline?->format(BaseSchemaBuilder::DATE_FORMAT),
			'checklist' => $this->mapCheckList($checkList),
			'group' => $this->mapGroupFromEntity($task->group),
			'priority' => $task->priority?->value,
			'status' => $task->status?->value,
			'parentTaskId' => $task->parent?->getId(),
			'link' => $this->getLink($task->getId(), $userId),
		];
	}

	private function mapCheckList(CheckList $checkList): array
	{
		$formatted = [];

		/** @var CheckListItem $item */
		foreach ($checkList as $item)
		{
			$formatted[] = [
				'id' => $item->getId(),
				'title' => $item->title,
				'parentId' => $item->parentId,
				'sortIndex' => $item->sortIndex,
			];
		}

		return $formatted;
	}

	private function mapDeadline(mixed $deadline): ?int
	{
		if (!$deadline instanceof DateTime)
		{
			return null;
		}

		return $deadline->getTimestamp();
	}

	private function mapTask(int $id): ?Task
	{
		if ($id <= 0)
		{
			return null;
		}

		return Task::mapFromId($id);
	}

	private function mapUserFromEntity(?User $user): ?array
	{
		if ($user === null)
		{
			return null;
		}

		return [
			'id' => $user->getId(),
			'name' => $user->name,
		];
	}

	private function mapGroupFromEntity(?Group $group): ?array
	{
		if ($group === null)
		{
			return null;
		}

		return [
			'id' => $group->getId(),
			'name' => $group->name,
			'type' => $group->type,
		];
	}

	private function mapUserFromArray(int $id, ?string $name, ?string $lastName): ?User
	{
		if ($id <= 0)
		{
			return null;
		}

		return new User($id, $this->mapUserName($name, $lastName));
	}

	private function mapGroupFromArray(int $id, ?string $name, ?string $type): ?Group
	{
		if ($id <= 0)
		{
			return null;
		}

		return new Group(id: $id, name: $name, type: $type);
	}

	private function mapUserName(?string $name, ?string $lastName): string
	{
		return $this->nameService->format(['NAME' => $name, 'LAST_NAME' => $lastName]);
	}

	private function getLink(int $taskId, int $userId): string
	{
		return $this->linkService->get(new Task($taskId), $userId);
	}
}

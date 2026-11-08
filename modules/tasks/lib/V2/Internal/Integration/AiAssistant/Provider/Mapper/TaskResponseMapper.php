<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\Mapper;

use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\V2\Internal\Entity\CheckList;
use Bitrix\Tasks\V2\Internal\Entity\CheckList\CheckListItem;
use Bitrix\Tasks\V2\Internal\Entity\Group;
use Bitrix\Tasks\V2\Internal\Entity\Result;
use Bitrix\Tasks\V2\Internal\Entity\ResultCollection;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\Mapper\Util\DateFormatter;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\PriorityMapper;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\TaskStatusMapper;
use Bitrix\Tasks\V2\Internal\Service\Link\LinkService;
use Bitrix\Tasks\V2\Internal\Service\NameService;

class TaskResponseMapper
{
	public function __construct(
		private readonly TaskStatusMapper $statusMapper,
		private readonly PriorityMapper $priorityMapper,
		private readonly UserResponseMapper $userResponseMapper,
		private readonly NameService $nameService,
		private readonly LinkService $linkService,
	)
	{
	}

	public function mapFromArray(
		array $taskData,
		int $userId,
		?CheckList $checkList = null,
		?string $groupDisplayType = null,
	): array
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

		return $this->mapFromEntity(
			task: $task,
			userId: $userId,
			checkList: $checkList,
			groupDisplayType: $groupDisplayType,
		);
	}

	public function mapFromEntity(
		Task $task,
		int $userId,
		?CheckList $checkList = null,
		?ResultCollection $results = null,
		?array $permissions = null,
		?string $groupDisplayType = null,
	): array
	{
		return [
			'taskId' => $task->id,
			'title' => $task->title,
			'description' => $task->description,
			'creator' => $this->userResponseMapper->mapFromEntity($task->creator),
			'responsible' => $this->userResponseMapper->mapFromEntity($task->responsible),
			'accomplices' => $this->userResponseMapper->mapFromEntityCollection($task->accomplices),
			'auditors' => $this->userResponseMapper->mapFromEntityCollection($task->auditors),
			'deadline' => DateFormatter::formatTimestamp($task->deadlineTs, DateFormatter::FORMAT_WITHOUT_TIMEZONE, $userId),
			'checklists' => $this->mapCheckLists($checkList),
			'results' => $this->mapResults($results, $userId),
			'group' => $this->mapGroupFromEntity($task->group, $groupDisplayType),
			'priority' => $task->priority?->value,
			'status' => $task->status?->value,
			'parentTaskId' => $task->parent?->getId(),
			'permissions' => $permissions,
			'link' => $this->getLink($task->getId(), $userId),
		];
	}

	private function mapCheckLists(?CheckList $checkList): ?array
	{
		if ($checkList === null)
		{
			return null;
		}

		return array_map(fn(CheckListItem $item): array => $this->mapCheckListItem($item), $checkList->getEntities());
	}

	private function mapCheckListItem(CheckListItem $item): array
	{
		return [
			'id' => $item->getId(),
			'title' => $item->title,
			'creator' => $this->userResponseMapper->mapFromEntity($item->creator),
			'parentId' => $item->parentId,
			'sortIndex' => $item->sortIndex,
			'isComplete' => $item->isComplete,
		];
	}

	private function mapResults(?ResultCollection $results, int $userId): ?array
	{
		if ($results === null)
		{
			return null;
		}

		return array_map(fn(Result $result): array => $this->mapResult($result, $userId), $results->getEntities());
	}

	private function mapResult(Result $result, int $userId): array
	{
		return [
			'id' => $result->getId(),
			'text' => $result->text,
			'author' => $this->userResponseMapper->mapFromEntity($result->author),
			'createdDate' => DateFormatter::formatTimestamp(
				$result->createdAtTs,
				DateFormatter::FORMAT_WITHOUT_TIMEZONE,
				$userId,
			),
		];
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

	private function mapGroupFromEntity(?Group $group, ?string $displayType): ?array
	{
		if ($group === null)
		{
			return null;
		}

		$result = [
			'id' => $group->id,
			'name' => $group->name,
			'type' => $group->type,
		];

		if ($displayType !== null)
		{
			$result['displayType'] = $displayType;
		}

		return $result;
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

<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Tasks\Internals\Task\MemberTable;
use Bitrix\Tasks\Internals\TaskTable;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\TaskMemberCollection;
use Bitrix\Tasks\V2\Internal\Entity\TaskCollection;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Entity\UserCollection;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\Task\OrmTaskMapper;

class SubTaskRepository implements SubTaskRepositoryInterface
{
	private const MEMBER_TYPE_TO_FIELD = [
		MemberTable::MEMBER_TYPE_AUDITOR => 'auditors',
		MemberTable::MEMBER_TYPE_ACCOMPLICE => 'accomplices',
	];

	public function __construct(
		private readonly OrmTaskMapper $ormTaskMapper,
		private readonly TaskMemberRepositoryInterface $taskMemberRepository,
	)
	{
	}

	public function containsSubTasks(int $parentId): bool
	{
		$result =
			TaskTable::query()
				->setSelect([new ExpressionField('EXISTS', 1)])
				->where('PARENT_ID', $parentId)
				->setLimit(1)
				->fetch()
		;

		return $result !== false;
	}

	public function getSubTaskIdsByParentIds(array $parentIds): array
	{
		$result = [];

		$tasks =
			TaskTable::query()
				->setSelect(['ID', 'PARENT_ID'])
				->whereIn('PARENT_ID', $parentIds)
				->fetchAll()
		;

		foreach ($tasks as $task)
		{
			$result[$task['PARENT_ID']] ??= [];
			$result[$task['PARENT_ID']][] = (int)$task['ID'];
		}

		return $result;
	}

	public function getByParentId(int $parentId, bool $withMembers = false): TaskCollection
	{
		$results =
			TaskTable::query()
				->setSelect(['*', 'UF_*'])
				->where('PARENT_ID', $parentId)
				->fetchAll()
		;

		if (empty($results))
		{
			return new TaskCollection();
		}

		$taskCollection = $this->ormTaskMapper->mapToCollection($results);

		if ($withMembers)
		{
			return $this->attachMembers($taskCollection);
		}

		return $taskCollection;
	}

	public function invalidate(int $taskId): void
	{

	}

	private function attachMembers(TaskCollection $tasks): TaskCollection
	{
		$taskIdToMembersMap = $this->mapMembersByTaskId($this->taskMemberRepository->getByTaskIds($tasks->getIds()));

		if (empty($taskIdToMembersMap))
		{
			return $tasks;
		}

		$tasksWithMembers = [];
		foreach ($tasks as $task)
		{
			$tasksWithMembers[] = $this->attachTaskMembers(
				task: $task,
				taskMembers: $taskIdToMembersMap[$task->getId()] ?? [],
			);
		}

		return new TaskCollection(...$tasksWithMembers);
	}

	private function mapMembersByTaskId(TaskMemberCollection $taskMembers): array
	{
		$result = [];

		foreach ($taskMembers as $taskMember)
		{
			$field = self::MEMBER_TYPE_TO_FIELD[$taskMember->type] ?? null;
			if ($field === null)
			{
				continue;
			}

			$result[$taskMember->taskId][$field] ??= new UserCollection();
			$result[$taskMember->taskId][$field]->add(new User($taskMember->userId));
		}

		return $result;
	}

	private function attachTaskMembers(Task $task, array $taskMembers): Task
	{
		return empty($taskMembers) ? $task : $task->cloneWith($taskMembers);
	}
}

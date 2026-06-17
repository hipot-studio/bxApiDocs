<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Tasks\Internals\TaskTable;
use Bitrix\Tasks\V2\Internal\Entity\TaskCollection;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\Task\OrmTaskMapper;

class SubTaskRepository implements SubTaskRepositoryInterface
{
	public function __construct(
		private readonly OrmTaskMapper $ormTaskMapper,
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

	public function getByParentId(int $parentId): TaskCollection
	{
		$results =
			TaskTable::query()
				->setSelect(['*', 'UF_*'])
				->where('PARENT_ID', $parentId)
				->fetchAll()
		;

		return $this->ormTaskMapper->mapToCollection($results);
	}

	public function invalidate(int $taskId): void
	{

	}
}

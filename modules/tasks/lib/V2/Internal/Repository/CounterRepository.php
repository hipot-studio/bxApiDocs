<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\Internals\Counter\CounterTable;
use Bitrix\Tasks\V2\Internal\Entity\CounterCollection;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\CounterMapper;

class CounterRepository implements CounterRepositoryInterface
{
	public function __construct(
		private readonly CounterMapper $mapper,
	) {
	}

	public function createFromCollection(CounterCollection $collection): void
	{
		if ($collection->isEmpty())
		{
			return;
		}

		CounterTable::addMulti($this->mapper->mapFromCollection($collection), true);
	}

	public function deleteByUserAndTaskAndType(int $userId, int|array $taskId, string $type): void
	{
		if (is_int($taskId))
		{
			$taskId = [$taskId];
		}

		CounterTable::deleteByFilter([
			'=USER_ID' => $userId,
			'@TASK_ID' => $taskId,
			'=TYPE' => $type,
		]);
	}
}

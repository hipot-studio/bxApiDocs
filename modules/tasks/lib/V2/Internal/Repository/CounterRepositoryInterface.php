<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\V2\Internal\Entity\CounterCollection;

interface CounterRepositoryInterface
{
	public function createFromCollection(CounterCollection $collection): void;

	/** @param int[] $taskId */
	public function deleteByUserAndTaskAndType(int $userId, array|int $taskId, string $type): void;

	/**
	 * Returns the new comments counter of every given task, muted counters included.
	 *
	 * @param int[] $taskIds
	 * @return array<int, int> counter value by task id, zero for tasks without counters. Keys are the
	 *                         positive unique ids of the input, so callers passing unchecked ids must not
	 *                         expect a key for every element they gave.
	 */
	public function getCommentsCountByUserAndTasks(int $userId, array $taskIds): array;
}

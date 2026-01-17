<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\V2\Internal\Entity\CounterCollection;

interface CounterRepositoryInterface
{
	public function createFromCollection(CounterCollection $collection): void;
	/** @param int[] $taskId */
	public function deleteByUserAndTaskAndType(int $userId, array|int $taskId, string $type): void;
}

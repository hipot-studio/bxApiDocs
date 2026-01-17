<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\V2\Internal\Entity\TaskCollection;

interface SubTaskRepositoryInterface
{
	public function containsSubTasks(int $parentId): bool;
	public function getByParentId(int $parentId): TaskCollection;
	public function invalidate(int $taskId): void;
}

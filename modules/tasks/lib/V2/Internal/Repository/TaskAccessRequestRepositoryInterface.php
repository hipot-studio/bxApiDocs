<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\V2\Internal\Entity\Task\AccessRequest;

interface TaskAccessRequestRepositoryInterface
{
	public function add(AccessRequest $accessRequest): void;

	public function get(int $userId, int $taskId): ?AccessRequest;

	public function isExists(int $userId, int $taskId): bool;

	public function clearByTime(int $createdDateTs): void;

	public function clearByTaskId(int $taskId): void;

	public function clearByUserId(int $userId): void;
}

<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\V2\Internal\Entity\Task\ElapsedTime;
use Bitrix\Tasks\V2\Internal\Entity\Task\ElapsedTimeCollection;

interface ElapsedTimeReadRepositoryInterface
{
	public function getList(int $taskId, int $limit, int $offset, array $order = []): ElapsedTimeCollection;
	public function getById(int $elapsedTimeId): ?ElapsedTime;

	public function getUsersContribution(int $taskId): array;
}

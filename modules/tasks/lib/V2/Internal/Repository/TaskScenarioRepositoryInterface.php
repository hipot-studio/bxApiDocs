<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\V2\Internal\Entity\Task\ScenarioCollection;

interface TaskScenarioRepositoryInterface
{
	public function getById(int $taskId): ScenarioCollection;

	public function save(int $taskId, array $scenarios): void;

	public function delete(int $taskId): void;
}

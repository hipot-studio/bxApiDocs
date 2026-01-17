<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\V2\Internal\Entity;

interface TaskLogRepositoryInterface
{
	public function add(Entity\HistoryLog $historyLog): int;

	public function tail(int $taskId, int $offset = 0): Entity\HistoryLogCollection;

	public function getLastByField(int $taskId, string $field): ?Entity\HistoryLog;

	/**
	 * Retrieve list of history logs for the specific field and values.
	 */
	public function tailWithFieldAndValues(int $taskId, string $field, mixed $fromValue = null, mixed $toValue = null, int $offset = 0, ?int $limit = 50): Entity\HistoryLogCollection;
}

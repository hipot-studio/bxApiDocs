<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\V2\Internal\Entity\HistoryLogCollection;

interface TaskHistoryRepositoryInterface
{
	public function tail(int $taskId, int $offset = 0, int $limit = 50): HistoryLogCollection;

	public function tailByFields(int $taskId, array $fields, int $offset = 0, int $limit = 50): array;

	public function getLastCreatedDateByFields(int $taskId, array $fields): ?DateTime;

	public function getLastCreatedDatesByFields(array $taskIds, array $fields): array;
}

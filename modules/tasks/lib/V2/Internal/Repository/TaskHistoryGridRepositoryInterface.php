<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\V2\Internal\Entity\HistoryGridLogCollection;

interface TaskHistoryGridRepositoryInterface
{
	public function tail(int $taskId, int $offset = 0, int $limit = 50): HistoryGridLogCollection;
}

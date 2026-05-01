<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Repository\Task\Filter;
use Bitrix\Tasks\V2\Internal\Repository\Task\ListSelect;
use Bitrix\Tasks\V2\Internal\Repository\Task\Order;
use Bitrix\Tasks\V2\Internal\Repository\Task\Select;

interface TaskReadRepositoryInterface
{
	public function getById(int $id, ?Select $select = null): ?Entity\Task;

	public function getAttachmentIds(int $taskId): array;

	public function getList(
		?Pagination $pagination = null,
		?ListSelect $select = null,
		?Order $order = null,
		?Filter $filter = null,
	): Entity\TaskCollection;

	public function getCount(?Filter $filter = null): int;
}

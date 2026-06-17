<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\V2\Internal\Entity\Task\View\ViewedUserCollection;

interface ViewedUserRepositoryInterface
{
	public function tail(int $taskId, int $offset, int $limit): ViewedUserCollection;

	public function getCount(int $taskId): int;
}

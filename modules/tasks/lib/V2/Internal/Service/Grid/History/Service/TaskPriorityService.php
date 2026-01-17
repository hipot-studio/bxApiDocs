<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\History\Service;

use Bitrix\Tasks\Internals\Task\Priority;

class TaskPriorityService
{
	public function fillPriority(int $priority): ?string
	{
		return Priority::getMessage($priority);
	}
}

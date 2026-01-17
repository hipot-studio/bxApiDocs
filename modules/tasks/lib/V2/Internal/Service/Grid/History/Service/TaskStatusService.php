<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\History\Service;

use Bitrix\Tasks\Internals\Task\Status;

class TaskStatusService
{
	public function fillStatus(int $status): ?string
	{
		return Status::getMessage($status);
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\MigrateRecentTasks;

use Bitrix\Tasks\V2\Internal\Async\AbstractBaseMessage;
use Bitrix\Tasks\V2\Internal\Async\QueueId;

class MigrateRecentTasksMessage extends AbstractBaseMessage
{
	public function __construct(
		public int $taskId,
	)
	{
	}

	public function jsonSerialize(): array
	{
		return [
			'taskId' => $this->taskId,
		];
	}

	protected function getQueueId(): QueueId
	{
		return QueueId::MigrateRecentTasks;
	}
}

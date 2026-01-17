<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Counter\Command;

use Bitrix\Tasks\Internals\Counter\Event\EventDictionary;

class TaskExpired extends AbstractPayload
{
	public function __construct
	(
		public int $taskId,
		public int $userId,
	) {
	}

	public function getCommand(): string
	{
		return EventDictionary::EVENT_TASK_EXPIRED;
	}

	/** @param array{TASK_ID: int, USER_ID: int} $data */
	public function toArray(): array
	{
		return ['TASK_ID' => $this->taskId, 'USER_ID' => $this->userId];
	}
}

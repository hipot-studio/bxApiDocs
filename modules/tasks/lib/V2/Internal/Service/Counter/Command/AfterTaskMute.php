<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Counter\Command;

use Bitrix\Tasks\Internals\Counter\Event\EventDictionary;

class AfterTaskMute extends AbstractPayload
{
	public function __construct
	(
		public int $taskId,
		public int $userId,
		public bool $added,
	) {
	}

	public function getCommand(): string
	{
		return EventDictionary::EVENT_AFTER_TASK_MUTE;
	}

	public function toArray(): array
	{
		return [
			'TASK_ID' => $this->taskId,
			'USER_ID' => $this->userId,
			'ADDED' => $this->added,
		];
	}
}

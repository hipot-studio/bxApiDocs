<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Counter\Command;

use Bitrix\Tasks\Internals\Counter\Event\EventDictionary;

class AfterUserMentioned extends AbstractPayload
{
	public function __construct
	(
		public int $userId,
		public int $taskId,
		public int $messageId,
		public ?int $groupId = null,
	) {
	}

	/** @return array{USER_ID: int, TASK_ID: int, MESSAGE_ID: int, GROUP_ID: ?int} */
	public function toArray(): array
	{
		return [
			'USER_ID' => $this->userId,
			'TASK_ID' => $this->taskId,
			'MESSAGE_ID' => $this->messageId,
			'GROUP_ID' => $this->groupId,
		];
	}

	public function getCommand(): string
	{
		return EventDictionary::EVENT_AFTER_USER_MENTIONED;
	}
}

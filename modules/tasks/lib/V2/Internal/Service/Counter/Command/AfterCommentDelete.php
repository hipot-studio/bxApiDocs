<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Counter\Command;

use Bitrix\Tasks\Internals\Counter\Event\EventDictionary;

class AfterCommentDelete extends AbstractPayload
{
	public function __construct
	(
		public int $userId,
		public int $taskId,
		public int $messageId,
	) {
	}

	public function getCommand(): string
	{
		return EventDictionary::EVENT_AFTER_COMMENT_DELETE;
	}

	/** @return array{USER_ID: int, TASK_ID: int, MESSAGE_ID: int} */
	public function toArray(): array
	{
		return [
			'USER_ID' => $this->userId,
			'TASK_ID' => $this->taskId,
			'MESSAGE_ID' => $this->messageId,
		];
	}
}

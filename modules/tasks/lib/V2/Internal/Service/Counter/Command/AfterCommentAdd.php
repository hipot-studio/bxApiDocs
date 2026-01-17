<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Counter\Command;

use Bitrix\Tasks\Comments\Internals\Comment;
use Bitrix\Tasks\Internals\Counter\Event\EventDictionary;

class AfterCommentAdd extends AbstractPayload
{
	public function __construct
	(
		public int $userId,
		public int $taskId,
		public int $messageId,
		public ?int $groupId = null,
		public int $serviceType = Comment::TYPE_DEFAULT,
	) {
	}

	public function getCommand(): string
	{
		return EventDictionary::EVENT_AFTER_COMMENT_ADD;
	}

	/** @return array{TASK_ID: int, USER_ID: int, GROUP_ID: ?int, MESSAGE_ID: int, SERVICE_TYPE: string} */
	public function toArray(): array
	{
		return [
			'TASK_ID' => $this->taskId,
			'USER_ID' => $this->userId,
			'GROUP_ID' => $this->groupId,
			'MESSAGE_ID' => $this->messageId,
			'SERVICE_TYPE' => $this->serviceType,
		];
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Counter\Command;

use Bitrix\Tasks\Internals\Counter\Event\EventDictionary;

class AfterCommentsReadList extends AbstractPayload
{
	public function __construct(
		public int $userId,
		public array $taskIds,
	) {
	}

	public function getCommand(): string
	{
		return EventDictionary::EVENT_AFTER_COMMENTS_READ_LIST;
	}

	/** @return array{USER_ID: int, TASK_IDS: array<int>} */
	public function toArray(): array
	{
		return [
			'USER_ID' => $this->userId,
			'TASK_IDS' => array_values($this->taskIds),
		];
	}
}

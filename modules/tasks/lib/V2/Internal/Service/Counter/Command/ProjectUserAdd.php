<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Counter\Command;

use Bitrix\Tasks\Internals\Counter\Event\EventDictionary;

class ProjectUserAdd extends AbstractPayload
{
	public function __construct
	(
		public int $groupId,
		public int $userId,
	) {
	}

	public function getCommand(): string
	{
		return EventDictionary::EVENT_PROJECT_USER_ADD;
	}

	/** @return array{GROUP_ID: int, USER_ID: int} */
	public function toArray(): array
	{
		return [
			'GROUP_ID' => $this->groupId,
			'USER_ID' => $this->userId,
		];
	}
}

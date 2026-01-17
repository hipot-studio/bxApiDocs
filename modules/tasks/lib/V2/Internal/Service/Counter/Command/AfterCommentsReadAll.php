<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Counter\Command;

use Bitrix\Tasks\Internals\Counter\Event\EventDictionary;

class AfterCommentsReadAll extends AbstractPayload
{
	public function __construct
	(
		public int $userId,
		public ?int $groupId = null,
		public ?string $role = null,
	) {
	}

	public function getCommand(): string
	{
		return EventDictionary::EVENT_AFTER_COMMENTS_READ_ALL;
	}

	/** @return array{USER_ID: int, GROUP_ID: ?int, ROLE: ?string} */
	public function toArray(): array
	{
		return [
			'USER_ID' => $this->userId,
			'GROUP_ID' => $this->groupId,
			'ROLE' => $this->role,
		];
	}
}

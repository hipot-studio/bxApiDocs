<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Counter\Command;

use Bitrix\Tasks\Internals\Counter\Event\EventDictionary;

class GarbageCollect extends AbstractPayload
{
	public function __construct
	(
		public int $userId,
	) {
	}

	public function getCommand(): string
	{
		return EventDictionary::EVENT_GARBAGE_COLLECT;
	}

	/** @return array{USER_ID: int} */
	public function toArray(): array
	{
		return ['USER_ID' => $this->userId];
	}
}

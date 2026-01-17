<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Counter\Command;

use Bitrix\Tasks\Internals\Counter\Event\EventDictionary;

class ProjectDelete extends AbstractPayload
{
	public function __construct
	(
		public int $groupId,
	) {
	}

	public function getCommand(): string
	{
		return EventDictionary::EVENT_PROJECT_DELETE;
	}

	/** @return array{GROUP_ID: int} */
	public function toArray(): array
	{
		return ['GROUP_ID' => $this->groupId];
	}
}

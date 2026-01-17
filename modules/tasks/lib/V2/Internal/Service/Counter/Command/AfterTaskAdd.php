<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Counter\Command;

use Bitrix\Tasks\Internals\Counter\Event\EventDictionary;

class AfterTaskAdd extends AbstractPayload
{
	public function __construct
	(
		public array $data,
	) {
	}

	public function getCommand(): string
	{
		return EventDictionary::EVENT_AFTER_TASK_ADD;
	}

	public function toArray(): array
	{
		return $this->data;
	}
}

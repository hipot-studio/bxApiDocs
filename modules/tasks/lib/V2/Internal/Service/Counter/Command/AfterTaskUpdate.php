<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Counter\Command;

use Bitrix\Tasks\Internals\Counter\Event\EventDictionary;

class AfterTaskUpdate extends AbstractPayload
{
	public function __construct
	(
		public array $oldRecord,
		public array $newRecord,
		public array $params,
	) {
	}

	public function getCommand(): string
	{
		return EventDictionary::EVENT_AFTER_TASK_UPDATE;
	}

	/** @return array{OLD_RECORD: array, NEW_RECORD: array, PARAMS: array} */
	public function toArray(): array
	{
		return [
			'OLD_RECORD' => $this->oldRecord,
			'NEW_RECORD' => $this->newRecord,
			'PARAMS' => $this->params,
		];
	}
}

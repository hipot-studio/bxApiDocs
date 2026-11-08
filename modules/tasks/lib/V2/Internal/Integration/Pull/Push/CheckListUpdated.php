<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Pull\Push;

use Bitrix\Tasks\Integration\Pull\PushCommand;
use Bitrix\Tasks\Integration\Pull\PushParam;

class CheckListUpdated extends AbstractPayload
{
	public function __construct(
		public int $taskId,
		public string $revision,
		public array $checklist,
		public bool $fromAi = false,
	)
	{
	}

	public function getCommand(): string
	{
		return PushCommand::CHECKLIST_UPDATED;
	}

	/** @return array{taskId: int, revision: string, checklist: array, FROM_AI?: bool} */
	public function toArray(): array
	{
		$result = [
			'taskId' => $this->taskId,
			'revision' => $this->revision,
			'checklist' => $this->checklist,
		];

		if ($this->fromAi === true)
		{
			$result[PushParam::FROM_AI] = true;
		}

		return $result;
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Pull\Command;

use Bitrix\Tasks\Integration\Pull\PushCommand;

/**
 * @method self payload(int $taskId, int $userId, int $option, bool $added)
 */
class UserOptionUpdated extends AbstractPayload
{
	public int $taskId;
	public int $userId;
	public int $option;
	public bool $added;

	public function getCommand(): string
	{
		return PushCommand::USER_OPTION_UPDATED;
	}

	/** @return array{TASK_ID: int, USER_ID: int, OPTION: int, ADDED: bool} */
	public function toArray(): array
	{
		return [
			'TASK_ID' => $this->taskId,
			'USER_ID' => $this->userId,
			'OPTION' => $this->option,
			'ADDED' => $this->added,
		];
	}
}

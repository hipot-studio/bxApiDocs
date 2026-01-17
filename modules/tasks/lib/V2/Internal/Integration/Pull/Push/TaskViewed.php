<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Pull\Push;

use Bitrix\Tasks\Integration\Pull\PushCommand;

class TaskViewed extends AbstractPayload
{
	public function __construct
	(
		public int $taskId,
		public int $userId,
		public ?int $groupId = null,
		public ?string $role = null,
	)
	{
	}

	public function getCommand(): string
	{
		return PushCommand::TASK_VIEWED;
	}

	/** @return array{TASK_ID: int, USER_ID: int, GROUP_ID: int|null, ROLE: string|null} */
	public function toArray(): array
	{
		return [
			'TASK_ID' => $this->taskId,
			'USER_ID' => $this->userId,
			'GROUP_ID' => $this->groupId,
			'ROLE' => $this->role,
		];
	}
}

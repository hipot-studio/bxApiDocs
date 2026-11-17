<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Pull\Push;

use Bitrix\Tasks\Integration\Pull\PushCommand;

class CommentsViewed extends AbstractPayload
{
	public function __construct
	(
		public int $userId,
		public ?int $groupId = null,
		public ?string $role = null,
		public ?int $taskId = null,
		public array $taskIds = [],
	)
	{
		$this->taskIds = $this->normalizeTaskIds($taskIds);
	}

	public function getCommand(): string
	{
		return PushCommand::COMMENTS_VIEWED;
	}

	/** @return array{USER_ID: int, GROUP_ID: int|null, ROLE: string|null, TASK_ID?: int, TASK_IDS?: list<int>} */
	public function toArray(): array
	{
		$payload = [
			'USER_ID' => $this->userId,
			'GROUP_ID' => $this->groupId,
			'ROLE' => $this->role,
		];

		if ($this->taskId !== null && $this->taskId > 0)
		{
			$payload['TASK_ID'] = $this->taskId;
		}

		if (!empty($this->taskIds))
		{
			$payload['TASK_IDS'] = $this->taskIds;
		}

		return $payload;
	}

	private function normalizeTaskIds(array $taskIds): array
	{
		$normalizedTaskIds = array_map(static fn(mixed $taskId): int => (int)$taskId, $taskIds);
		$normalizedTaskIds = array_filter($normalizedTaskIds, static fn(int $taskId): bool => $taskId > 0);

		return array_values(array_unique($normalizedTaskIds));
	}
}

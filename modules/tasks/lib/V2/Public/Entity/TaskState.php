<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Entity;

class TaskState
{
	public function __construct(
		public readonly int $status,
		public readonly bool $isExpired,
		public readonly ?int $deadlineTs = null,
		public readonly ?int $endPlanTs = null,
	)
	{
	}

	public function isEqualTo(self $taskState): bool
	{
		return $this->status === $taskState->status
			&& $this->isExpired === $taskState->isExpired
			&& $this->deadlineTs === $taskState->deadlineTs
			&& $this->endPlanTs === $taskState->endPlanTs
		;
	}
}

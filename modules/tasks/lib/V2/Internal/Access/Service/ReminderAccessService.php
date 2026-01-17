<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Service;

use Bitrix\Tasks\Access\ActionDictionary;

class ReminderAccessService
{
	public function __construct(
		private readonly TaskAccessService $taskAccessService,
	)
	{
	}

	public function canAdd(int $userId, int $taskId, array $params = []): bool
	{
		return $this->taskAccessService->can($userId, ActionDictionary::ACTION_TASK_REMINDER, $taskId, $params);
	}
}

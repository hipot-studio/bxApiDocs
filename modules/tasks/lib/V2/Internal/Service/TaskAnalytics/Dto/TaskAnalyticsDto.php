<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\TaskAnalytics\Dto;

use Bitrix\Tasks\V2\Internal\Service\TaskAnalytics\Enum\ChecklistMovementState;

final class TaskAnalyticsDto
{
	public function __construct(
		public readonly bool $isControlNeeded,
		public readonly bool $hasResults,
		public readonly ?ChecklistMovementState $checklistMovement,
		public readonly ?int $createdWorkDaysAgo,
		public readonly ?int $workDaysUntilDeadline,
		public readonly ?int $workDaysOverdue,
		public readonly ?int $controlWaitingSinceWorkDays,
		public readonly ?int $lastSignificantUpdateWorkDaysAgo,
	)
	{
	}
}

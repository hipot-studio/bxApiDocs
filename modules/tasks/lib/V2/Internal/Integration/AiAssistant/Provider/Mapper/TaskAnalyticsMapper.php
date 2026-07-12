<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\Mapper;

use Bitrix\Tasks\V2\Internal\Service\TaskAnalytics\Dto\TaskAnalyticsDto;

class TaskAnalyticsMapper
{
	public function map(TaskAnalyticsDto $analytics): array
	{
		return [
			'isControlNeeded' => $analytics->isControlNeeded,
			'hasResults' => $analytics->hasResults,
			'checklistMovement' => $analytics->checklistMovement?->value,
			'createdWorkDaysAgo' => $analytics->createdWorkDaysAgo,
			'workDaysUntilDeadline' => $analytics->workDaysUntilDeadline,
			'workDaysOverdue' => $analytics->workDaysOverdue,
			'controlWaitingSinceWorkDays' => $analytics->controlWaitingSinceWorkDays,
			'lastSignificantUpdateWorkDaysAgo' => $analytics->lastSignificantUpdateWorkDaysAgo,
		];
	}
}

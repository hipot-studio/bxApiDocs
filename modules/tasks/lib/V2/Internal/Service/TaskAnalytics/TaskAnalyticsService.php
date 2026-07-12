<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\TaskAnalytics;

use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\TaskCollection;
use Bitrix\Tasks\V2\Internal\Service\TaskAnalytics\Dto\TaskAnalyticsDto;

class TaskAnalyticsService
{
	public function __construct(
		private readonly TaskAnalyticsContextBuilder $contextBuilder,
		private readonly TaskAnalyticsComputer $computer,
	)
	{
	}

	public function getTaskAnalytics(Task $task): TaskAnalyticsDto
	{
		$analysisContext = $this->contextBuilder->buildForTask($task);

		return $this->computer->computeForTask($task, $analysisContext, time());
	}

	public function getTasksAnalytics(TaskCollection $tasks): array
	{
		$analysisContext = $this->contextBuilder->buildForTasks($tasks);

		return $this->computer->computeForTasks($tasks, $analysisContext, time());
	}
}

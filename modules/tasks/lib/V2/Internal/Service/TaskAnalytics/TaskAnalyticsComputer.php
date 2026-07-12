<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\TaskAnalytics;

use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\Task\Status;
use Bitrix\Tasks\V2\Internal\Entity\TaskCollection;
use Bitrix\Tasks\V2\Internal\Service\TaskAnalytics\Dto\AnalyticsContext;
use Bitrix\Tasks\V2\Internal\Service\TaskAnalytics\Dto\TaskAnalyticsDto;
use Bitrix\Tasks\V2\Internal\Service\TaskAnalytics\Enum\ChecklistMovementState;
use Bitrix\Tasks\V2\Internal\Service\TaskAnalytics\Helper\WorkDaysHelper;

class TaskAnalyticsComputer
{
	public function __construct(
		private readonly WorkDaysHelper $workDaysHelper,
	)
	{
	}

	/**
	 * @return TaskAnalyticsDto[]
	 */
	public function computeForTasks(TaskCollection $tasks, AnalyticsContext $context, int $nowTs): array
	{
		$result = [];

		foreach ($tasks as $task)
		{
			$result[$task->id] = $this->computeForTask($task, $context, $nowTs);
		}

		return $result;
	}

	public function computeForTask(Task $task, AnalyticsContext $context, int $nowTs): TaskAnalyticsDto
	{
		return new TaskAnalyticsDto(
			isControlNeeded: $this->computeIsControlNeeded($task),
			hasResults: $this->computeHasResults($task, $context),
			checklistMovement: $this->computeChecklistMovement($task, $context),
			createdWorkDaysAgo: $this->computeCreatedWorkDaysAgo($task, $nowTs),
			workDaysUntilDeadline: $this->computeWorkDaysUntilDeadline($task, $nowTs),
			workDaysOverdue: $this->computeWorkDaysOverdue($task, $nowTs),
			controlWaitingSinceWorkDays: $this->computeControlWaitingSinceWorkDays($task, $nowTs),
			lastSignificantUpdateWorkDaysAgo: $this->computeLastSignificantUpdateWorkDaysAgo($task, $context, $nowTs),
		);
	}

	private function computeIsControlNeeded(Task $task): bool
	{
		return $task->needsControl === true;
	}

	private function computeHasResults(Task $task, AnalyticsContext $context): bool
	{
		return $context->resultsExistenceMap[$task->id] ?? false;
	}

	private function computeChecklistMovement(Task $task, AnalyticsContext $context): ?ChecklistMovementState
	{
		$checklistCompletedItemsExists = $context->checklistCompletedItemsExistenceMap[$task->id] ?? null;

		if (!is_bool($checklistCompletedItemsExists))
		{
			return null;
		}

		return
			$checklistCompletedItemsExists
				? ChecklistMovementState::HasMovement
				: ChecklistMovementState::NoMovement
		;
	}

	private function computeCreatedWorkDaysAgo(Task $task, int $nowTs): ?int
	{
		return $this->workDaysHelper->calculateBetween($task->createdTs, $nowTs);
	}

	private function computeWorkDaysUntilDeadline(Task $task, int $nowTs): ?int
	{
		return $this->workDaysHelper->calculateBetween($nowTs, $task->deadlineTs);
	}

	private function computeWorkDaysOverdue(Task $task, int $nowTs): ?int
	{
		return $this->workDaysHelper->calculateBetween($task->deadlineTs, $nowTs);
	}

	private function computeControlWaitingSinceWorkDays(Task $task, int $nowTs): ?int
	{
		if ($task->status !== Status::SupposedlyCompleted)
		{
			return null;
		}

		return $this->workDaysHelper->calculateBetween($task->statusChangedTs, $nowTs);
	}

	private function computeLastSignificantUpdateWorkDaysAgo(Task $task, AnalyticsContext $context, int $nowTs): ?int
	{
		$historyLastDateTs = null;
		$historyLastDate = $context->lastSignificantHistoryDatesMap[$task->id] ?? null;
		if ($historyLastDate instanceof DateTime)
		{
			$historyLastDateTs = $historyLastDate->getTimestamp();
		}

		$messagesLastDateTs = null;
		$messagesLastDate = $context->chatLastMessageDatesMap[$task->id] ?? null;
		if ($messagesLastDate instanceof DateTime)
		{
			$messagesLastDateTs = $messagesLastDate->getTimestamp();
		}

		$lastDateTs = max($historyLastDateTs, $messagesLastDateTs);

		return $this->workDaysHelper->calculateBetween($lastDateTs, $nowTs);
	}
}

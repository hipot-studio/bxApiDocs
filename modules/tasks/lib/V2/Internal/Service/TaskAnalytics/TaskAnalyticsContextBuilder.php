<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\TaskAnalytics;

use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\TaskCollection;
use Bitrix\Tasks\V2\Internal\Repository\CheckListRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\TaskAnalytics\Dto\AnalyticsContext;
use Bitrix\Tasks\V2\Internal\Service\TaskAnalytics\Enum\SignificantHistoryField;
use Bitrix\Tasks\V2\Internal\Integration\Im\Repository\MessageRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TaskHistoryRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TaskResultRepositoryInterface;

class TaskAnalyticsContextBuilder
{
	public function __construct(
		private readonly TaskResultRepositoryInterface $taskResultRepository,
		private readonly CheckListRepositoryInterface $taskCheckListRepository,
		private readonly TaskHistoryRepositoryInterface $taskHistoryRepository,
		private readonly MessageRepositoryInterface $taskMessagesRepository,
	)
	{
	}

	public function buildForTask(Task $task): AnalyticsContext
	{
		if ($task->id < 1)
		{
			return new AnalyticsContext();
		}

		return new AnalyticsContext(
			resultsExistenceMap: $this->getTaskResultsExistenceMap($task),
			checklistCompletedItemsExistenceMap: $this->getTaskChecklistCompletedItemsExistenceMap($task),
			lastSignificantHistoryDatesMap: $this->getTaskLastSignificantHistoryDatesMap($task),
			chatLastMessageDatesMap: $this->getTaskChatLastMessageDatesMap($task),
		);
	}

	public function buildForTasks(TaskCollection $tasks): AnalyticsContext
	{
		if ($tasks->isEmpty())
		{
			return new AnalyticsContext();
		}

		return new AnalyticsContext(
			resultsExistenceMap: $this->getTasksResultsExistenceMap($tasks),
			checklistCompletedItemsExistenceMap: $this->getTasksChecklistCompletedItemsExistenceMap($tasks),
			lastSignificantHistoryDatesMap: $this->getTasksLastSignificantHistoryDatesMap($tasks),
			chatLastMessageDatesMap: $this->getTasksChatLastMessageDatesMap($tasks),
		);
	}

	private function getTaskResultsExistenceMap(Task $task): array
	{
		return [
			$task->id => $this->taskResultRepository->containsResults((int)$task->id),
		];
	}

	private function getTasksResultsExistenceMap(TaskCollection $tasks): array
	{
		return $this->taskResultRepository->getTasksResultsExistenceMap($tasks->getIdList());
	}

	private function getTaskChecklistCompletedItemsExistenceMap(Task $task): array
	{
		return $this->taskCheckListRepository->getTasksCompletedItemsExistenceMap([$task->id]);
	}

	private function getTasksChecklistCompletedItemsExistenceMap(TaskCollection $tasks): array
	{
		return $this->taskCheckListRepository->getTasksCompletedItemsExistenceMap($tasks->getIdList());
	}

	private function getTaskLastSignificantHistoryDatesMap(Task $task): array
	{
		$result = $this->taskHistoryRepository->getLastCreatedDateByFields(
			$task->id,
			SignificantHistoryField::values(),
		);

		return [$task->id => $result];
	}

	private function getTasksLastSignificantHistoryDatesMap(TaskCollection $tasks): array
	{
		return $this->taskHistoryRepository->getLastCreatedDatesByFields(
			$tasks->getIdList(),
			SignificantHistoryField::values(),
		);
	}

	private function getTaskChatLastMessageDatesMap(Task $task): array
	{
		if ($task->chatId === null || $task->chatId <= 0)
		{
			return [$task->id => null];
		}

		return [
			$task->id => $this->taskMessagesRepository->getChatLastMessageDate($task->chatId),
		];
	}

	private function getTasksChatLastMessageDatesMap(TaskCollection $tasks): array
	{
		$result = [];

		foreach ($tasks as $task)
		{
			if ($task->chatId !== null && $task->chatId > 0)
			{
				$result[$task->id] = $this->taskMessagesRepository->getChatLastMessageDate($task->chatId);
			}
		}

		return $result;
	}
}

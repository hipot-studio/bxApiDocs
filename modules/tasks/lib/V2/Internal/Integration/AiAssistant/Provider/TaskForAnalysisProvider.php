<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider;

use Bitrix\Tasks\V2\Internal\Entity\TaskCollection;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\NotFoundException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\NotSuitableForAnalysisException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Enum\ProjectTasksForAnalysisSort;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Helper\TaskStatusesNotSuitableForAnalysisHelper;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\Mapper\ProjectTasksForAnalysisResponseMapper;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\Mapper\TaskForAnalysisResponseMapper;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\TaskAnalytics\ProjectTaskListRequestBuilder;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\TaskAnalytics\ProjectTaskListRequestDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\GetProjectTasksForAnalysisDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\GetTaskForAnalysisDto;
use Bitrix\Tasks\V2\Internal\Repository\Task\Select;
use Bitrix\Tasks\V2\Internal\Repository\TaskReadRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\TaskAnalytics\TaskAnalyticsService;

class TaskForAnalysisProvider
{
	public function __construct(
		private readonly TaskReadRepositoryInterface $taskReadRepository,
		private readonly TaskAnalyticsService $taskAnalyticsService,
		private readonly TaskMessagesProvider $taskMessagesProvider,
		private readonly TaskHistoryProvider $taskHistoryProvider,
		private readonly StageProvider $stageProvider,
		private readonly ProjectTaskListRequestBuilder $taskListRequestBuilder,
		private readonly TaskForAnalysisResponseMapper $taskForAnalysisResponseMapper,
		private readonly ProjectTasksForAnalysisResponseMapper $projectTasksForAnalysisResponseMapper,
	)
	{
	}

	/**
	 * @throws NotFoundException
	 * @throws NotSuitableForAnalysisException
	 */
	public function getTask(int $userId, GetTaskForAnalysisDto $dto): array
	{
		$task = $this->taskReadRepository->getById(
			$dto->taskId,
			new Select(group: true, stage: true, members: true),
		);

		if ($task === null)
		{
			throw new NotFoundException();
		}

		if (in_array($task->status, TaskStatusesNotSuitableForAnalysisHelper::getList(), true))
		{
			throw new NotSuitableForAnalysisException();
		}

		$analytics = $this->taskAnalyticsService->getTaskAnalytics($task);

		$recentMessages = null;
		if ($dto->messagesLimit > 0)
		{
			$recentMessages = [];
			if ($task->chatId !== null)
			{
				$recentMessages = $this->taskMessagesProvider->getRecentMessages($task->chatId, $dto->messagesLimit);
			}
		}

		$significantHistory = null;
		if ($dto->historyLimit > 0)
		{
			$significantHistory = $this->taskHistoryProvider->getSignificantHistory($dto->taskId, $dto->historyLimit);
		}

		return $this->taskForAnalysisResponseMapper->map(
			$task,
			$userId,
			$analytics,
			$recentMessages,
			$significantHistory,
		);
	}

	public function getProjectTasks(int $userId, GetProjectTasksForAnalysisDto $dto): array
	{
		$tasks = $this->loadTasks($userId, $dto);

		if ($tasks->isEmpty())
		{
			return $this->projectTasksForAnalysisResponseMapper->map($tasks, $dto->projectId, $userId);
		}

		$analytics = $this->taskAnalyticsService->getTasksAnalytics($tasks);

		$stageTitles = $this->stageProvider->getTitlesByGroupId((int)$dto->projectId);

		return $this->projectTasksForAnalysisResponseMapper->map(
			$tasks,
			$dto->projectId,
			$userId,
			$analytics,
			$stageTitles,
		);
	}

	private function loadTasks(int $userId, GetProjectTasksForAnalysisDto $dto): TaskCollection
	{
		if ($dto->sort === ProjectTasksForAnalysisSort::RecentlyChanged)
		{
			return $this->loadTasksFromRepository(
				$this->taskListRequestBuilder->buildRecentlyChanged($userId, $dto),
			);
		}

		$tasks = $this->loadTasksFromRepository(
			$this->taskListRequestBuilder->buildDeadlineFilled($userId, $dto),
		);

		if ($tasks->count() >= $dto->limit)
		{
			return $tasks;
		}

		return $tasks->merge(
			$this->loadTasksFromRepository(
				$this->taskListRequestBuilder->buildDeadlineEmpty($userId, $dto, $dto->limit - $tasks->count()),
			),
		);
	}

	private function loadTasksFromRepository(ProjectTaskListRequestDto $dto): TaskCollection
	{
		return $this->taskReadRepository->getList(
			$dto->pagination,
			$dto->listSelect,
			$dto->order,
			$dto->filter,
		);
	}
}

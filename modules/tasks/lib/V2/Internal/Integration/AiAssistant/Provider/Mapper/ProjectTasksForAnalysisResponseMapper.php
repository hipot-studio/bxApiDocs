<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\Mapper;

use Bitrix\Tasks\V2\Internal\Entity\Priority;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\TaskCollection;
use Bitrix\Tasks\V2\Internal\Service\Link\LinkService;
use Bitrix\Tasks\V2\Internal\Service\TaskAnalytics\Dto\TaskAnalyticsDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\Mapper\Util\DateFormatter;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\Mapper\Util\TextFormatter;

class ProjectTasksForAnalysisResponseMapper
{
	public const TITLE_MAX_LENGTH = 150;

	public function __construct(
		private readonly TaskAnalyticsMapper $taskAnalyticsMapper,
		private readonly LinkService $linkService,
	)
	{
	}

	/**
	 * @param TaskAnalyticsDto[] $analytics
	 */
	public function map(
		TaskCollection $tasks,
		int $projectId,
		int $userId,
		array $analytics = [],
		array $stageTitles = [],
	): array
	{
		$tasksResponse = [];

		foreach ($tasks as $task)
		{
			if ($task->id === null)
			{
				continue;
			}

			$analyticsDto = $analytics[$task->id] ?? null;

			$stageTitle = null;
			if ($task->stageId !== null)
			{
				$stageTitle = $stageTitles[$task->stageId] ?? null;
			}

			$tasksResponse[] = $this->mapTask($task, $userId, $analyticsDto, $stageTitle);
		}

		return [
			'projectId' => $projectId,
			'tasks' => $tasksResponse,
		];
	}

	private function mapTask(
		Task $task,
		int $userId,
		?TaskAnalyticsDto $analyticsDto = null,
		?string $stageTitle = null,
	): array
	{
		$result = [
			'taskId' => $task->id,
			'title' => TextFormatter::truncate($task->title, self::TITLE_MAX_LENGTH),
			'status' => $task->status?->value,
			'deadline' => DateFormatter::formatTimestamp($task->deadlineTs),
			'createdDate' => DateFormatter::formatTimestamp($task->createdTs),
			'isImportant' => $task->priority === Priority::High,
			'stage' => $stageTitle,
			'link' => $this->linkService->get($task, $userId),
			'analytics' => null,
		];

		if ($analyticsDto)
		{
			$result['analytics'] = $this->taskAnalyticsMapper->map($analyticsDto);
		}

		return $result;
	}
}

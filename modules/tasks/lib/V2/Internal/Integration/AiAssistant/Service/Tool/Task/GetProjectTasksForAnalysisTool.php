<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\DtoValidationException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Helper\TaskStatusesNotSuitableForAnalysisHelper;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\TaskForAnalysisProvider;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\GetProjectTasksForAnalysisDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder\TaskSchemaBuilder;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\BaseTool;
use Bitrix\Tasks\V2\Internal\Integration\Intranet\Service\ToolService;
use Bitrix\Tasks\V2\Internal\Integration\Socialnetwork\Service\GroupAccessService;

class GetProjectTasksForAnalysisTool extends BaseTool
{
	public const ACTION_NAME = 'get_project_tasks_for_analysis';

	public function __construct(
		private readonly TaskForAnalysisProvider $taskForAnalysisProvider,
		private readonly GroupAccessService $accessService,
		ToolService $toolService,
		TaskSchemaBuilder $schemaBuilder,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($toolService, $schemaBuilder, $validationService, $tracedLogger);
	}

	public function getDescription(): string
	{
		return
			'Returns active tasks of a project (group) with per-task detailed analytics: '
			. 'metadata plus an "analytics" block with formal signs of progress and risks. '
			. 'WHEN TO USE: questions about overall project state, problematic tasks, risks, '
			. 'or a user\'s personal workload within the project. '
			. 'PREREQ: requires a known "projectId". Resolve it before calling. '
			. 'FILTERS (optional): responsibleId (assignee), creatorId (task author). '
			. 'SIZE: "limit" (1..50, default 25) - size of the returned sample. '
			. 'The tool always returns a single sample per call; offset-based pagination is not supported. '
			. 'SORT: the "sort" parameter controls the order in which tasks are selected '
			. 'from the database and then fill the sample when the total number of matches exceeds "limit". '
			. 'Values: "recently_changed" (default; selects most recently updated first, best for '
			. '"what changed" / overall status) '
			. 'or "deadline" (selects tasks with the closest deadline first, best for '
			. 'risk-of-missing-deadline analysis; tasks without a deadline, ordered by least recent change first, '
			. 'are added at the end of the sample if there is room). '
			. 'NOTATION: a value of "0" in "analytics" block "workDays" fields means "today". '
			. 'NEVER render it as "0 days" in your answer - say "today" (e.g. "deadline today", "updated today"). '
			. 'WARNING: returns only a BOUNDED SAMPLE of project tasks (limit, default 25, max 50) - NOT the full project snapshot. '
			. 'NEVER claim project-wide absence based on this result (e.g. "no overdue tasks in the project"); '
			. 'qualify with "among the recent active tasks I reviewed" or skip the absence claim. '
			. 'Treat counts as lower bounds when the returned sample size equals limit. '
			. 'DOES NOT return ' . TaskStatusesNotSuitableForAnalysisHelper::getListForDescription() . ' tasks. '
			. 'DOES NOT return full tasks data. '
			. 'DOES NOT return tasks from other projects. '
			. 'DOES NOT modify any task.'
		;
	}

	public function canRun(int $userId): bool
	{
		parent::canRun($userId);

		if (!$this->toolService->isProjectsAvailable())
		{
			throw new McpException(
				'The Projects feature in Tasks has been disabled in this Bitrix24 by the administrator, '
				. 'so tasks cannot be retrieved by project. '
				. 'Contact the administrator of this Bitrix24 to re-enable Projects',
			);
		}

		return true;
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$dto = GetProjectTasksForAnalysisDto::fromArray($args);

		try
		{
			$this->validate($dto);
		}
		catch (DtoValidationException $e)
		{
			throw new McpException($e->getMessage(), previous: $e);
		}

		if (!$this->accessService->canView($userId, $dto->projectId))
		{
			throw new McpException('Access denied to project ' . $dto->projectId . '.');
		}

		return $this->taskForAnalysisProvider->getProjectTasks($userId, $dto);
	}
}

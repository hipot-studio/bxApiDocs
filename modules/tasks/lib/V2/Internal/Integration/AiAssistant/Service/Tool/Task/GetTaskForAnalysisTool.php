<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Tasks\V2\Internal\Integration\Intranet\Service\ToolService;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Tasks\V2\Internal\Access\Service\TaskAccessService;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\DtoValidationException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\NotFoundException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\NotSuitableForAnalysisException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Helper\TaskStatusesNotSuitableForAnalysisHelper;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\TaskForAnalysisProvider;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\GetTaskForAnalysisDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder\TaskSchemaBuilder;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\BaseTool;

class GetTaskForAnalysisTool extends BaseTool
{
	public const ACTION_NAME = 'get_task_for_analysis';

	public function __construct(
		private readonly TaskForAnalysisProvider $taskForAnalysisProvider,
		private readonly TaskAccessService $accessService,
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
			'Returns detailed analytics for a single active task: metadata '
			. 'plus an "analytics" block with formal signs of progress and risks. '
			. 'WHEN TO USE: the user asks why a specific task is problematic, where '
			. 'it is stuck, why it is on control, or what happened with it. '
			. 'PREREQ: requires a known taskId. If the user references the task by name, '
			. 'first resolve it via ' . SearchTasksTool::ACTION_NAME . '. '
			. 'OPTIONS: pass messagesLimit (1..50) to include recent chat messages; '
			. 'pass historyLimit (1..50) to include significant change history; '
			. 'for each, omit the parameter or pass null to skip that section. '
			. 'In recentHistory, entries for fields RESULT, RESULT_EDIT and DESCRIPTION omit fromValue/toValue. '
			. 'NOTATION: a value of "0" in "analytics" block "workDays" fields means "today". '
			. 'NEVER render it as "0 days" in your answer - say "today" (e.g. "deadline today", "updated today"). '
			. 'DOES NOT work for ' . TaskStatusesNotSuitableForAnalysisHelper::getListForDescription() . ' '
			. 'tasks - returns an error. '
			. 'DOES NOT return full task data - use get_task_by_id for that. '
			. 'DOES NOT modify the task.'
		;
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$dto = GetTaskForAnalysisDto::fromArray($args);

		try
		{
			$this->validate($dto);
		}
		catch (DtoValidationException $e)
		{
			throw new McpException($e->getMessage(), previous: $e);
		}

		if (!$this->accessService->canRead($userId, $dto->taskId))
		{
			throw new McpException('Access denied to task ' . $dto->taskId . '.');
		}

		try
		{
			$task = $this->taskForAnalysisProvider->getTask($userId, $dto);
		}
		catch (NotFoundException)
		{
			throw new McpException('Task ' . $dto->taskId . ' not found. It may have been deleted.');
		}
		catch (NotSuitableForAnalysisException)
		{
			throw new McpException(
				'Task ' . $dto->taskId . ' is in a terminal state ('
				. TaskStatusesNotSuitableForAnalysisHelper::getListForDescription()
				. ') and cannot be analyzed.',
			);
		}

		return $task;
	}
}

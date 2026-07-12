<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Tasks\V2\Internal\Integration\Intranet\Service\ToolService;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Tasks\Provider\Exception\TaskListException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\DtoValidationException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\TaskProvider;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\SearchTasksDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder\TaskSchemaBuilder;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\BaseTool;

class SearchTasksTool extends BaseTool
{
	public const ACTION_NAME = 'search_tasks';

	public function __construct(
		private readonly TaskProvider $taskProvider,
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
			"Searches for tasks based on various criteria. Returns their identifiers, names and deadline. "
			. "If you want to search for tasks by 'RESPONSIBLE_ID', 'CREATED_BY' or 'GROUP_ID', "
			. "the value must be a numeric identifier, not a user's or group's name. "
			. "If you don't know identifiers, use another tools for find it firstly. "
			. 'Each task\'s "group" includes id, name and type. '
			. 'NOTATION: when a task\'s "group" contains a "displayType" field, '
			. 'you MUST use group.displayType — not group.type — whenever you mention that task\'s group '
			. 'or project to the user.'
		;
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$dto = SearchTasksDto::fromArray($args);

		try
		{
			$this->validate($dto);

			$tasks = $this->taskProvider->getList($dto, $userId);
		}
		catch (DtoValidationException|TaskListException $e)
		{
			throw new McpException($e->getMessage(), previous: $e);
		}

		return [
			'tasks' => $tasks,
		];
	}
}

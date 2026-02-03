<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Main\Web\Json;
use Bitrix\Tasks\Provider\Exception\TaskListException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\TaskProvider;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\SearchTasksDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\DtoValidationException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder\TaskSchemaBuilder;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\BaseTool;

class SearchTasksTool extends BaseTool
{
	public const ACTION_NAME = 'search_tasks';

	public function __construct(
		private readonly TaskProvider $taskProvider,
		TaskSchemaBuilder $schemaBuilder,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($schemaBuilder, $validationService, $tracedLogger);
	}

	public function getDescription(): string
	{
		return
			"Searches for tasks based on various criteria. Returns their identifiers, names and deadline. "
			. "If you want to search for tasks by 'RESPONSIBLE_ID', 'CREATED_BY' or 'GROUP_ID', "
			. "the value must be a numeric identifier, not a user's or group's name. "
			. "If you don't know identifiers, use another tools for find it firstly."
		;
	}

	/**
	 * @throws ArgumentException
	 */
	protected function execute(int $userId, ...$args): string
	{
		$dto = SearchTasksDto::fromArray($args);

		try
		{
			$this->validate($dto);

			$tasks = $this->taskProvider->getList($dto, $userId);
		}
		catch (DtoValidationException|TaskListException $e)
		{
			return $this->createFailureResponse($e->getMessage());
		}

		if (empty($tasks))
		{
			return 'Tasks not found.';
		}

		return 'Tasks successfully found: ' . Json::encode($tasks) . '.';
	}
}

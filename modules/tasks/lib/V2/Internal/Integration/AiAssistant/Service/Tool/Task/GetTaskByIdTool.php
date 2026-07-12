<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Tasks\V2\Internal\Integration\Intranet\Service\ToolService;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Main\Web\Json;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\AccessDeniedException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\InvalidIdentifierException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\TaskProvider;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\GetTaskByIdDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\DtoValidationException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder\TaskSchemaBuilder;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\BaseTool;

class GetTaskByIdTool extends BaseTool
{
	public const ACTION_NAME = 'get_task_by_id';

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
			'Retrieves full task data by its identifier. '
			. 'The returned task\'s "group" includes id, name and type. '
			. 'NOTATION: when "group" contains a "displayType" field, '
			. 'you MUST use group.displayType — not group.type — whenever you mention the task\'s group '
			. 'or project to the user.'
		;
	}

	protected function execute(int $userId, ...$args): string
	{
		$dto = GetTaskByIdDto::fromArray($args);

		try
		{
			$this->validate($dto);

			$task = $this->taskProvider->getById($dto, $userId);
		}
		catch (DtoValidationException $e)
		{
			return $this->createFailureResponse($e->getMessage());
		}
		catch (AccessDeniedException)
		{
			return $this->createFailureResponse('Access denied.');
		}
		catch (InvalidIdentifierException)
		{
			return $this->createFailureResponse('The provided task identifier is invalid.');
		}

		if ($task === null)
		{
			return 'Task not found.';
		}

		return 'Task successfully found: ' . Json::encode($task) . '.';
	}
}

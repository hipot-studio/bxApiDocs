<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Tasks\Control\Exception\TaskAddException;
use Bitrix\Tasks\Control\Exception\TaskNotExistsException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\AccessDeniedException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\DtoValidationException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\CreateTaskDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder\TaskSchemaBuilder;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\TaskService;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\BaseTool;

class CreateTaskTool extends BaseTool
{
	public const ACTION_NAME = 'create_task';

	public function __construct(
		private readonly TaskService $taskService,
		TaskSchemaBuilder $schemaBuilder,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($schemaBuilder, $validationService, $tracedLogger);
	}

	public function getDescription(): string
	{
		return 'Creates a new task with the provided title and other details.';
	}

	protected function execute(int $userId, ...$args): string
	{
		$dto = CreateTaskDto::fromArray([...$args, 'userId' => $userId]);

		try
		{
			$this->validate($dto);

			$task = $this->taskService->create($dto, $userId);
		}
		catch (DtoValidationException|TaskAddException $e)
		{
			return $this->createFailureResponse($e->getMessage());
		}
		catch (AccessDeniedException)
		{
			return $this->createFailureResponse('Access denied.');
		}
		catch (TaskNotExistsException)
		{
			return $this->createFailureResponse('Task not found.');
		}

		return "Task '{$task->title}' with id '{$task->id}' successfully created.";
	}
}

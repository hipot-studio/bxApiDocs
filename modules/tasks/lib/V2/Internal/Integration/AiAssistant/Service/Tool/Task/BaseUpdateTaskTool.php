<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Main\SystemException;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Tasks\Control\Exception\WrongTaskIdException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\AccessDeniedException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\DtoValidationException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\InvalidIdentifierException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\NotFoundException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\UpdateTaskDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder\TaskSchemaBuilder;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\TaskService;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\BaseTool;

abstract class BaseUpdateTaskTool extends BaseTool
{
	public const ACTION_NAME = 'update_task';

	public function __construct(
		private readonly TaskService $taskService,
		TaskSchemaBuilder $schemaBuilder,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($schemaBuilder, $validationService, $tracedLogger);
	}

	abstract protected function buildDto(array $args, int $userId): UpdateTaskDto;

	protected function execute(int $userId, ...$args): string
	{
		$dto = $this->buildDto($args, $userId);

		try
		{
			$this->validate($dto);

			$this->taskService->update($dto, $userId);
		}
		catch (AccessDeniedException)
		{
			return $this->createFailureResponse('Access denied.');
		}
		catch (NotFoundException)
		{
			return $this->createFailureResponse('The task does not exist.');
		}
		catch (InvalidIdentifierException|WrongTaskIdException)
		{
			return $this->createFailureResponse('The provided task identifier is invalid.');
		}
		catch (DtoValidationException|SystemException $e)
		{
			return $this->createFailureResponse($e->getMessage());
		}

		return 'Task successfully updated.';
	}

	protected function validate(object $dto): void
	{
		parent::validate($dto);

		if ($dto->isEmpty())
		{
			throw new DtoValidationException('At least one field to update must be provided.');
		}
	}
}

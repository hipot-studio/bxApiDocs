<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Tasks\Control\Exception\TaskUpdateException;
use Bitrix\Tasks\Control\Exception\WrongTaskIdException;
use Bitrix\Tasks\V2\Internal\Entity\Task\Status;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\AccessDeniedException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\DtoValidationException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\InvalidIdentifierException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\NotFoundException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\UpdateTaskDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder\TaskSchemaBuilder;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\TaskService;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\BaseTool;
use Bitrix\Tasks\V2\Internal\Integration\Intranet\Service\ToolService;
use Bitrix\Tasks\V2\Internal\Service\TariffService;

abstract class BaseUpdateTaskTool extends BaseTool
{
	public const ACTION_NAME = 'update_task';

	public function __construct(
		private readonly TariffService $tariffService,
		private readonly TaskService $taskService,
		ToolService $toolService,
		TaskSchemaBuilder $schemaBuilder,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($toolService, $schemaBuilder, $validationService, $tracedLogger);
	}

	abstract protected function buildDto(array $args, int $userId): UpdateTaskDto;

	/**
	 * @throws McpException
	 */
	protected function execute(int $userId, ...$args): string
	{
		$dto = $this->buildDto($args, $userId);

		$this->assertAvailable($dto);

		try
		{
			$this->validate($dto);

			$this->taskService->update($dto, $userId);
		}
		catch (AccessDeniedException)
		{
			throw new McpException('Access denied');
		}
		catch (NotFoundException)
		{
			throw new McpException('The task does not exist');
		}
		catch (InvalidIdentifierException|WrongTaskIdException)
		{
			throw new McpException('The provided task identifier is invalid');
		}
		catch (DtoValidationException|CommandValidationException|TaskUpdateException $e)
		{
			throw new McpException($e->getMessage(), previous: $e);
		}

		return 'Task successfully updated.';
	}

	/**
	 * @throws McpException
	 */
	private function assertAvailable(UpdateTaskDto $dto): void
	{
		if ($dto->groupId !== null && $dto->groupId > 0)
		{
			if (!$this->toolService->isProjectsAvailable())
			{
				throw new McpException(
					'The Projects feature in Tasks has been disabled in this Bitrix24 by the administrator, '
					. 'so a task cannot be linked to a project. '
					. 'Contact the administrator of this Bitrix24 to re-enable Projects',
				);
			}

			if (!$this->tariffService->isProjectAvailable($dto->groupId))
			{
				throw new McpException(
					'The Projects feature is not available on the current Bitrix24 plan, '
					. 'so a task cannot be linked to a project. '
					. 'Upgrade to a suitable Bitrix24 plan to enable this',
				);
			}
		}

		if (
			$dto->status === Status::SupposedlyCompleted
			&& !$this->tariffService->isEnabled($this->tariffService->getControlFeatureId())
		)
		{
			throw new McpException(
				'The Task control feature is not available on the current Bitrix24 plan, '
				. 'so a task cannot be set to the "supposedly completed" state for creator review. '
				. 'Upgrade to a suitable Bitrix24 plan to enable this',
			);
		}
	}

	protected function validate(object $dto): void
	{
		parent::validate($dto);

		if ($dto->isEmpty())
		{
			throw new DtoValidationException('At least one field to update must be provided');
		}
	}
}

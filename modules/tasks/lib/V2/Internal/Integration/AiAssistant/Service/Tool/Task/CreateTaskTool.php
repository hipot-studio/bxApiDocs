<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Tasks\Control\Exception\TaskAddException;
use Bitrix\Tasks\Control\Exception\TaskNotExistsException;
use Bitrix\Tasks\V2\Internal\Entity\Task\Status;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\AccessDeniedException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\DtoValidationException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\CreateTaskDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder\TaskSchemaBuilder;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\TaskService;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\BaseTool;
use Bitrix\Tasks\V2\Internal\Integration\Intranet\Service\ToolService;
use Bitrix\Tasks\V2\Internal\Service\TariffService;

class CreateTaskTool extends BaseTool
{
	public const ACTION_NAME = 'create_task';

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

	public function getDescription(): string
	{
		return
			'Creates a new task with the provided title and other details. '
			. 'The returned task\'s "group" includes id, name and type. '
			. 'NOTATION: when "group" contains a "displayType" field, '
			. 'you MUST use group.displayType — not group.type — whenever you mention the task\'s group '
			. 'or project to the user.'
		;
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$dto = CreateTaskDto::fromArray([...$args, 'userId' => $userId]);

		$this->assertAvailable($dto);

		try
		{
			$this->validate($dto);

			$task = $this->taskService->create($dto, $userId);
		}
		catch (DtoValidationException|TaskAddException $e)
		{
			throw new McpException(message: $e->getMessage(), previous: $e);
		}
		catch (AccessDeniedException)
		{
			throw new McpException('Access denied.');
		}
		catch (TaskNotExistsException)
		{
			throw new McpException('Task not found.');
		}

		return $task;
	}

	/**
	 * @throws McpException
	 */
	private function assertAvailable(CreateTaskDto $dto): void
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
			(!empty($dto->accompliceIds) || !empty($dto->auditorIds))
			&& !$this->tariffService->isStakeholderAvailable()
		)
		{
			throw new McpException(
				'The Observers and Participants feature is not available on the current Bitrix24 plan, '
				. 'so accomplices and auditors cannot be added to a task. '
				. 'Upgrade to a suitable Bitrix24 plan to enable this',
			);
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
}

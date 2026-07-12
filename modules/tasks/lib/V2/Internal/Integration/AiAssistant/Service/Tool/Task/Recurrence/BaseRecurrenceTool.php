<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\Recurrence;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Main\SystemException;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Tasks\Control\Exception\WrongTaskIdException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\AccessDeniedException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\DtoValidationException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\InvalidIdentifierException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\NotFoundException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\MakeTaskRecurringDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder\TaskSchemaBuilder;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\TaskService;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\TemplateService;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\BaseTool;
use Bitrix\Tasks\V2\Internal\Integration\Intranet\Service\ToolService;
use Bitrix\Tasks\V2\Internal\Service\TariffService;

abstract class BaseRecurrenceTool extends BaseTool
{
	public function __construct(
		private readonly TariffService $tariffService,
		private readonly TaskService $taskService,
		private readonly TemplateService $templateService,
		ToolService $toolService,
		TaskSchemaBuilder $schemaBuilder,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($toolService, $schemaBuilder, $validationService, $tracedLogger);
	}

	abstract protected function buildDto(array $args): MakeTaskRecurringDto;

	public function canRun(int $userId): bool
	{
		parent::canRun($userId);

		if (!$this->tariffService->isEnabled($this->tariffService->getRecurringTasksFeatureId()))
		{
			throw new McpException(
				'The Recurring Tasks feature is not available on the current Bitrix24 plan, '
				. 'so a task cannot be made recurring. '
				. 'Upgrade to a suitable Bitrix24 plan to enable this',
			);
		}

		return true;
	}

	protected function execute(int $userId, ...$args): string
	{
		$dto = $this->buildDto($args);

		try
		{
			$this->validate($dto);

			$this->taskService->markAsRecurring($dto, $userId);

			$this->templateService->ensureRecurringTemplate($dto, $userId);
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

		return 'Task successfully made recurring.';
	}
}

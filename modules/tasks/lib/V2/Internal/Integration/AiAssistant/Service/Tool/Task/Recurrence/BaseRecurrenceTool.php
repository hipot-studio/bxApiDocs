<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\Recurrence;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Tasks\Control\Exception\WrongTaskIdException;
use Bitrix\Tasks\Feature\RegularTaskTypesFeature;
use Bitrix\Tasks\Replication\Template\Enum\RegularityType;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\AccessDeniedException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\DtoValidationException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\InvalidIdentifierException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\NotFoundException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\MakeTaskRecurringDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\Recurrence\RecurrenceRuleTargetDto;
use Bitrix\Tasks\V2\Internal\Service\Replication\Recurrence\RecurrenceTemplateResolver;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder\TaskSchemaBuilder;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\TaskService;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\TemplateService;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\BaseTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\Recurrence\Trait\RecurrenceFailureTrait;
use Bitrix\Tasks\V2\Internal\Integration\Intranet\Service\ToolService;
use Bitrix\Tasks\V2\Internal\Service\TariffService;
use Throwable;

abstract class BaseRecurrenceTool extends BaseTool
{
	use RecurrenceFailureTrait;

	public const TEMPLATE_UNAVAILABLE_MESSAGE = 'The recurring task template is unavailable.';
	public const TASK_TARGET_UNAVAILABLE_MESSAGE = 'The task or its recurring task template is unavailable.';

	public function __construct(
		private readonly TariffService $tariffService,
		private readonly TaskService $taskService,
		private readonly TemplateService $templateService,
		ToolService $toolService,
		TaskSchemaBuilder $schemaBuilder,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
		private readonly RecurrenceTemplateResolver $recurrenceTemplateResolver,
	)
	{
		parent::__construct($toolService, $schemaBuilder, $validationService, $tracedLogger);
	}

	abstract protected function buildDto(array $args, int $userId): MakeTaskRecurringDto;

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
		try
		{
			$target = RecurrenceRuleTargetDto::fromArray($args);
			$this->validate($target);
			$resolvedTarget = $this->recurrenceTemplateResolver->resolveRuleTarget(
				$target->templateId,
				$target->taskId,
				$userId,
			);
			if ($resolvedTarget === null)
			{
				return $this->createFailureResponse(
					$target->templateId !== null
						? self::TEMPLATE_UNAVAILABLE_MESSAGE
						: self::TASK_TARGET_UNAVAILABLE_MESSAGE,
				);
			}

			unset($args['taskId']);
			unset($args['templateId']);
			if ($resolvedTarget['taskId'] !== null)
			{
				$args['taskId'] = $resolvedTarget['taskId'];
			}

			$dto = $this->buildDto($args, $userId);
		}
		catch (DtoValidationException $e)
		{
			return $this->createFailureResponse($e->getMessage());
		}
		catch (Throwable $e)
		{
			return $this->createServiceFailureResponse($e);
		}

		if (
			!Feature::isEnabled(RegularTaskTypesFeature::class)
			&& (
				$dto->regularityType === RegularityType::AfterCompletion
				|| $dto->regularityType === RegularityType::Deadline
				|| $dto->deadlineOffset !== null
				|| $dto->deadlineType !== null
				|| $dto->matchesWorkTime !== null
			)
		)
		{
			throw new McpException(
				'The new recurring task types and deadline settings are not available in this Bitrix24, '
				. 'so the requested recurrence cannot be configured',
			);
		}

		try
		{
			$this->validate($dto);

			if ($resolvedTarget['templateId'] !== null)
			{
				$this->templateService->updateRecurringTemplate($resolvedTarget['templateId'], $dto, $userId);
			}
			else
			{
				$this->taskService->markAsRecurring($dto, $userId);
				$this->templateService->ensureRecurringTemplate($dto, $userId);
			}
		}
		catch (AccessDeniedException)
		{
			return $this->createFailureResponse(
				$target->templateId !== null
					? self::TEMPLATE_UNAVAILABLE_MESSAGE
					: self::ACCESS_DENIED_MESSAGE,
			);
		}
		catch (NotFoundException)
		{
			if ($target->templateId !== null)
			{
				return $this->createFailureResponse(self::TEMPLATE_UNAVAILABLE_MESSAGE);
			}

			return $this->createFailureResponse(
				$resolvedTarget['templateId'] !== null
					? 'The recurring task template does not exist.'
					: 'The task does not exist.',
			);
		}
		catch (InvalidIdentifierException|WrongTaskIdException)
		{
			if ($target->templateId !== null)
			{
				return $this->createFailureResponse(self::TEMPLATE_UNAVAILABLE_MESSAGE);
			}

			return $this->createFailureResponse(
				$resolvedTarget['templateId'] !== null
					? 'The provided template identifier is invalid.'
					: 'The provided task identifier is invalid.',
			);
		}
		catch (DtoValidationException $e)
		{
			return $this->createFailureResponse($e->getMessage());
		}
		catch (Throwable $e)
		{
			return $this->createServiceFailureResponse($e);
		}

		if ($target->templateId !== null)
		{
			return "Recurring task rule successfully updated for templateId {$target->templateId}.";
		}

		if ($resolvedTarget['templateId'] !== null)
		{
			return "Recurring task rule successfully updated for taskId {$target->taskId}.";
		}

		return 'Task successfully made recurring.';
	}
}

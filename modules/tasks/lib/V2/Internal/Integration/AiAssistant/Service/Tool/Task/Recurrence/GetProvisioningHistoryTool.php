<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\Recurrence;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\DtoValidationException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\Recurrence\GetProvisioningHistoryDto;
use Bitrix\Tasks\V2\Internal\Service\Replication\Recurrence\RecurrenceTemplateResolver;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder\TaskSchemaBuilder;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\BaseTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\Recurrence\Trait\RecurrenceFailureTrait;
use Bitrix\Tasks\V2\Internal\Integration\Intranet\Service\ToolService;
use Bitrix\Tasks\V2\Public\Provider\Template\Params\TemplateHistoryParams;
use Bitrix\Tasks\V2\Public\Provider\Template\TemplateHistoryProvider;
use Throwable;

class GetProvisioningHistoryTool extends BaseTool
{
	use RecurrenceFailureTrait;

	public const ACTION_NAME = 'get_provisioning_history';
	public const HISTORY_UNAVAILABLE_MESSAGE = 'The requested history is unavailable.';

	public function __construct(
		private readonly TemplateHistoryProvider $templateHistoryProvider,
		private readonly RecurrenceTemplateResolver $recurrenceTemplateResolver,
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
			'Returns an object with an entries array containing one page of the general change history '
			. 'for one recurring task template, original recurring task, or generated task instance. '
			. 'This is not a log of task provisioning attempts or replication errors.'
		;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				...GetProvisioningHistoryDto::getIdentifierProperties(),
				'limit' => [
					'type' => 'integer',
					'description' => 'Maximum number of newest history entries to return on this page.',
					'minimum' => GetProvisioningHistoryDto::LIMIT_MIN,
					'maximum' => GetProvisioningHistoryDto::LIMIT_MAX,
					'default' => GetProvisioningHistoryDto::LIMIT_DEFAULT,
				],
				'offset' => [
					'type' => 'integer',
					'description' => 'Number of newest history entries to skip before returning this page.',
					'minimum' => GetProvisioningHistoryDto::OFFSET_MIN,
					'maximum' => GetProvisioningHistoryDto::OFFSET_MAX,
					'default' => 0,
				],
			],
			'oneOf' => GetProvisioningHistoryDto::getIdentifierOneOf(),
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		try
		{
			$dto = GetProvisioningHistoryDto::fromArray($args);
			$this->validate($dto);
		}
		catch (DtoValidationException $e)
		{
			throw $this->createValidationFailureException($e);
		}
		catch (Throwable $e)
		{
			throw $this->createServiceFailureException($e);
		}

		try
		{
			$templateId = $this->recurrenceTemplateResolver->resolveOne(
				$dto->templateId,
				$dto->taskId,
				$userId,
			);
		}
		catch (Throwable $e)
		{
			throw $this->createServiceFailureException($e);
		}

		if ($templateId === null)
		{
			throw $this->createValidationFailureException(
				new DtoValidationException(self::HISTORY_UNAVAILABLE_MESSAGE),
			);
		}

		try
		{
			// Task-based targets are already access-filtered by RecurrenceTemplateResolver.
			$checkAccess = !$dto->usesTaskId();
			$params = new TemplateHistoryParams(
				templateId: $templateId,
				userId: $userId,
				pager: new Pager(limit: $dto->limit, offset: $dto->offset),
				checkAccess: $checkAccess,
			);

			$result = ['entries' => $this->templateHistoryProvider->tail($params)->toArray()];
			if (!$dto->usesTaskId())
			{
				return $result;
			}

			return [
				'taskId' => $dto->taskId,
				'templateId' => $templateId,
				...$result,
			];
		}
		catch (Throwable $e)
		{
			throw $this->createServiceFailureException($e);
		}
	}
}

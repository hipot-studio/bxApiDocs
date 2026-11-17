<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\Recurrence;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Tasks\V2\Internal\Access\Service\TemplateAccessService;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\DtoValidationException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\Recurrence\TemplateIdsDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder\TaskSchemaBuilder;
use Bitrix\Tasks\V2\Internal\Integration\Intranet\Service\ToolService;
use Bitrix\Tasks\V2\Internal\Service\Replication\Batch\BatchReplicateStateService;
use Bitrix\Tasks\V2\Internal\Service\Replication\Recurrence\RecurrenceRuleService;
use Bitrix\Tasks\V2\Internal\Service\Replication\Recurrence\RecurrenceTemplateResolver;
use Bitrix\Tasks\V2\Internal\Service\TariffService;
use Throwable;

class ResumeRecurrenceTool extends BaseTemplateRecurrenceTool
{
	public const ACTION_NAME = 'resume_recurrence';

	public function __construct(
		private readonly BatchReplicateStateService $batchReplicateStateService,
		private readonly RecurrenceRuleService $recurrenceRuleService,
		private readonly TemplateAccessService $templateAccessService,
		private readonly TariffService $tariffService,
		RecurrenceTemplateResolver $recurrenceTemplateResolver,
		ToolService $toolService,
		TaskSchemaBuilder $schemaBuilder,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct(
			$recurrenceTemplateResolver,
			$toolService,
			$schemaBuilder,
			$validationService,
			$tracedLogger,
		);
	}

	public function getDescription(): string
	{
		return
			'Resumes recurrence for up to 200 task templates or recurring tasks in one call. '
			. 'Submit either one templateIds batch or one taskIds batch. '
			. 'Returns an object with a results array containing one result per supplied ID; '
			. 'the batch may partially succeed.'
		;
	}

	public function getInputSchema(): array
	{
		return TemplateIdsDto::getInputSchema();
	}

	/**
	 * Resuming is the only batch action that turns provisioning back on, so it also keeps the tariff gate.
	 *
	 * @throws McpException
	 */
	public function canRun(int $userId): bool
	{
		parent::canRun($userId);

		if (!$this->tariffService->isEnabled($this->tariffService->getRecurringTasksFeatureId()))
		{
			throw new McpException(
				'The Recurring Tasks feature is not available on the current Bitrix24 plan, '
				. 'so recurrence cannot be resumed. '
				. 'Upgrade to a suitable Bitrix24 plan to enable this',
			);
		}

		return true;
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		try
		{
			$dto = TemplateIdsDto::fromArray($args);
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
			$targets = $this->recurrenceTemplateResolver->resolve($dto->templateIds, $dto->taskIds, $userId);
			$templateIds = $this->recurrenceTemplateResolver->getTemplateIds($targets);

			$idsWithoutRule = $this->recurrenceRuleService->filterWithoutRule($templateIds);
			$templatesWithoutRule = array_map(
				static fn (int $templateId): Entity\Template => new Entity\Template(
					id: $templateId,
					replicate: true,
				),
				$idsWithoutRule,
			);
			$accessByTemplateId = $idsWithoutRule === []
				? []
				: $this->templateAccessService->canSaveBatch($userId, $templatesWithoutRule)
			;
			$deniedIds = array_values(
				array_filter(
					$idsWithoutRule,
					static fn (int $templateId): bool => !($accessByTemplateId[$templateId] ?? false),
				),
			);
			$idsToResume = array_values(array_diff($templateIds, $idsWithoutRule));

			$serviceResults = $idsToResume === []
				? []
				: $this->batchReplicateStateService->setState($idsToResume, $userId, true)
			;

			return [
				'results' => $this->buildTargetResults(
					$targets,
					$serviceResults,
					$idsWithoutRule,
					$deniedIds,
				),
			];
		}
		catch (Throwable $e)
		{
			throw $this->createServiceFailureException($e);
		}
	}
}

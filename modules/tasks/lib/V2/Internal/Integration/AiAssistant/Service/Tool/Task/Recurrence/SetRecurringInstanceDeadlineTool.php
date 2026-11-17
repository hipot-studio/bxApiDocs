<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\Recurrence;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Tasks\Replication\Template\Enum\DeadlineType;
use Bitrix\Tasks\V2\Internal\Access\Service\TemplateAccessService;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Entity\Template\ReplicateParams;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\DtoValidationException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\Recurrence\SetRecurringInstanceDeadlineDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder\TaskSchemaBuilder;
use Bitrix\Tasks\V2\Internal\Integration\Intranet\Service\ToolService;
use Bitrix\Tasks\V2\Internal\Repository\Template\TemplateReplicateParamsRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Replication\Recurrence\RecurrenceRuleService;
use Bitrix\Tasks\V2\Internal\Service\Replication\Recurrence\RecurrenceTemplateResolver;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Public\Command\Template\UpdateTemplateCommand;
use Throwable;

class SetRecurringInstanceDeadlineTool extends BaseTemplateRecurrenceTool
{
	public const ACTION_NAME = 'set_recurring_instance_deadline';

	public function __construct(
		private readonly TemplateAccessService $templateAccessService,
		private readonly RecurrenceRuleService $recurrenceRuleService,
		private readonly TemplateReplicateParamsRepositoryInterface $replicateParamsRepository,
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
			'Sets the deadline offset in seconds for future task instances created from one recurring template. '
			. 'Identify it with templateId or with taskId of the original recurring task or a generated instance. '
			. 'Optionally defines whether only working time counts toward the offset. '
			. 'deadlineType=' . DeadlineType::CreationDay->value . ' with deadlineAfter 0 or omitted puts the deadline '
			. 'on the creation day; deadlineAfter 0 without deadlineType removes the deadline. '
			. 'A positive deadlineAfter replaces deadlineType.'
		;
	}

	protected function execute(int $userId, ...$args): string
	{
		try
		{
			$dto = SetRecurringInstanceDeadlineDto::fromArray($args);
			$this->validate($dto);
		}
		catch (DtoValidationException $e)
		{
			return $this->createFailureResponse($e->getMessage());
		}
		catch (Throwable $e)
		{
			return $this->createServiceFailureResponse($e);
		}

		try
		{
			$templateId = $this->recurrenceTemplateResolver->resolveOne(
				$dto->templateId,
				$dto->taskId,
				$userId,
			);
			if ($templateId === null)
			{
				return $this->createFailureResponse(self::TASK_WITHOUT_TEMPLATE_MESSAGE);
			}

			$replicateParams = $dto->setsCreationDay() ? $this->buildCreationDayReplicateParams($templateId) : null;

			$template =
				$dto->matchesWorkTime === null
					? new Entity\Template(
						id: $templateId,
						deadlineAfter: $dto->deadlineAfter,
						replicateParams: $replicateParams,
					)
					: new Entity\Template(
						id: $templateId,
						deadlineAfter: $dto->deadlineAfter,
						replicateParams: $replicateParams,
						matchesWorkTime: $dto->matchesWorkTime,
					)
			;

			if (!$this->templateAccessService->canSave($userId, $template))
			{
				return $this->createFailureResponse(
					$dto->usesTaskId() ? self::TASK_WITHOUT_TEMPLATE_MESSAGE : self::ACCESS_DENIED_MESSAGE,
				);
			}

			if (
				($dto->setsCreationDay() && $replicateParams === null)
				|| !$this->recurrenceRuleService->hasRule($templateId)
			)
			{
				return $this->createFailureResponse(self::NO_RECURRENCE_RULE_MESSAGE);
			}

			$result = (new UpdateTemplateCommand(
				template: $template,
				config: new UpdateConfig(userId: $userId),
			))->run();
		}
		catch (Throwable $e)
		{
			return $this->createServiceFailureResponse($e);
		}

		if (!$result->isSuccess())
		{
			return $this->createResultFailureResponse($result);
		}

		if (!$dto->usesTaskId())
		{
			return 'Recurring task instance deadline successfully updated.';
		}

		return "Recurring task instance deadline successfully updated for task {$dto->taskId} (template {$templateId}).";
	}

	/**
	 * Passed REPLICATE_PARAMS replace the stored ones entirely, so the current schedule is carried along.
	 */
	private function buildCreationDayReplicateParams(int $templateId): ?ReplicateParams
	{
		$existing = $this->replicateParamsRepository->getByTemplateId($templateId)?->replicateParams;
		if ($existing === null)
		{
			return null;
		}

		return ReplicateParams::mapFromArray([
			...$existing->toArray(),
			'deadlineOffset' => 0,
			'deadlineType' => DeadlineType::CreationDay->value,
		]);
	}
}

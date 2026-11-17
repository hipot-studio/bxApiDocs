<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\Recurrence;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Tasks\Replication\Template\Enum\DeadlineType;
use Bitrix\Tasks\V2\Internal\Access\Registry\TemplateAccessCacheLoader;
use Bitrix\Tasks\V2\Internal\Access\Service\TemplateRightService;
use Bitrix\Tasks\V2\Internal\Entity\Template;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\DtoValidationException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\Recurrence\GetRecurrenceRuleDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder\TaskSchemaBuilder;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\BaseTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\Recurrence\Trait\RecurrenceFailureTrait;
use Bitrix\Tasks\V2\Internal\Integration\Intranet\Service\ToolService;
use Bitrix\Tasks\V2\Internal\Service\Replication\Recurrence\RecurrenceRuleService;
use Bitrix\Tasks\V2\Internal\Service\Replication\Recurrence\RecurrenceTemplateResolver;
use Throwable;

class GetRecurrenceRuleTool extends BaseTool
{
	use RecurrenceFailureTrait;

	public const ACTION_NAME = 'get_recurrence_rule';

	public function __construct(
		private readonly RecurrenceRuleService $recurrenceRuleService,
		private readonly TemplateAccessCacheLoader $templateAccessCacheLoader,
		private readonly TemplateRightService $templateRightService,
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
			'Returns an object with a rules array for up to 200 task templates or recurring tasks in input order. '
			. 'Submit either one templateIds batch or one taskIds batch. '
			. 'A missing or inaccessible template is returned with rule set to null and no error key. '
			. 'If reading a single template fails, its item adds an error key next to rule set to null '
			. 'and the rest of the batch is still returned. '
			. 'The MCP adapter returns rule.replicateParams.time and nextExecutionTime in the current user local time. '
			. 'Use time as an HH:MM wall-clock value unchanged and never convert it to or from UTC. '
			. 'nextExecutionTime is a full local date-time in the portal culture format; when replicate is active '
			. 'and nextExecutionTime is non-null, use it as the next scheduled run. '
			. 'Never infer or state a time zone from rule.replicateParams.timezoneOffset: it is a deprecated '
			. 'server-relative correction, and a zero value does not identify UTC. '
			. 'Name a zone only when trusted context identifies the current user time zone; never label it as UTC. '
			. 'The rule.replicateParams.yearlyMonth1 and yearlyMonth2 values are zero-based calendar months: '
			. '0 is January, 10 is November, and 11 is December. '
			. 'Other raw mappings are: rule.regularityType 0=schedule, 1=deadline, 2=after-completion; '
			. 'monthlyType/yearlyType 1=calendar day, 2=ordinal weekday; weekDays 1=Monday through 7=Sunday; '
			. 'monthlyWeekDay/yearlyWeekDay 0=Monday through 6=Sunday; '
			. 'monthlyWeekDayNum/yearlyWeekDayNum 0=first through 4=last; workdayOnly Y=yes, N=no. '
			. 'rule.replicateParams.deadlineType=' . DeadlineType::CreationDay->value
			. ' means the deadline is on the task creation day; '
			. 'otherwise a deadlineOffset of 0 means no deadline. '
			. 'For every non-null rule, report rule.replicate: true/Y means active; false/N means inactive and '
			. 'future provisioning is not currently scheduled. A false value alone does not identify why it stopped. '
			. 'repeatTill=endless is only the configured stop condition and does not make an inactive rule active. '
			. 'For every successful taskIds item with a non-null templateId, the user-facing answer must contain '
			. 'a separate source-template line with that exact ID; omitting it makes the answer incomplete. '
			. 'The resolved templateId is not secret. In subsequent tool calls, keep the original selector namespace: '
			. 'use taskId/taskIds after a taskIds input, and templateId/templateIds after a templateIds input.'
		;
	}

	public function getInputSchema(): array
	{
		return GetRecurrenceRuleDto::getInputSchema();
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		try
		{
			$dto = GetRecurrenceRuleDto::fromArray($args);
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
			$targets = $this->recurrenceTemplateResolver->resolveForReading(
				$dto->templateIds,
				$dto->taskIds,
				$userId,
			);
			$templateIds = $this->recurrenceTemplateResolver->getTemplateIds($targets);

			if (!$dto->usesTaskIds())
			{
				$this->templateAccessCacheLoader->preloadForAccess($userId, $templateIds);
			}
			$rulesResult = $this->recurrenceRuleService->getRulesResult($templateIds, $userId);
			$rules = $rulesResult->getRules();
		}
		catch (Throwable $e)
		{
			throw $this->createServiceFailureException($e);
		}

		$result = [];
		foreach ($targets as $target)
		{
			$templateId = $target['templateId'];
			if ($templateId === null)
			{
				$result[] = $dto->usesTaskIds()
					? ['taskId' => $target['taskId'], 'templateId' => null, 'rule' => null]
					: ['templateId' => null, 'rule' => null]
				;

				continue;
			}

			try
			{
				if ($rulesResult->hasFailedTemplate($templateId))
				{
					if (!$dto->usesTaskIds() && !$this->templateRightService->canView($userId, $templateId))
					{
						$result[] = ['templateId' => $templateId, 'rule' => null];

						continue;
					}

					$result[] = $this->buildFailedItem(
						$target['taskId'],
						$templateId,
						$dto->usesTaskIds(),
					);

					continue;
				}

				$rule = $this->buildRule(
					$userId,
					$templateId,
					$rules,
					$dto->usesTaskIds(),
				);
				$identifier = $dto->usesTaskIds()
					? [
						'taskId' => $target['taskId'],
						'templateId' => isset($rules[$templateId]) ? $templateId : null,
					]
					: ['templateId' => $templateId]
				;

				$result[] = [
					...$identifier,
					'rule' => $rule,
				];
			}
			catch (Throwable $e)
			{
				$this->logFailure($e);

				$result[] = $this->buildFailedItem(
					$target['taskId'],
					$templateId,
					$dto->usesTaskIds(),
				);
			}
		}

		return ['rules' => $result];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildFailedItem(?int $taskId, int $templateId, bool $usesTaskIds): array
	{
		return [
			...($usesTaskIds
				? ['taskId' => $taskId, 'templateId' => null]
				: ['templateId' => $templateId]
			),
			'rule' => null,
			'error' => self::SERVICE_FAILURE_MESSAGE,
		];
	}

	/**
	 * @param array<int, Template> $rules
	 * @return array<string, mixed>|null
	 */
	private function buildRule(
		int $userId,
		int $templateId,
		array $rules,
		bool $accessAlreadyChecked,
	): ?array
	{
		// A missing template answers with the same `rule: null` as an inaccessible one, so the access
		// check is only worth running for templates that were actually read.
		$template = $rules[$templateId] ?? null;
		if (
			$template === null
			|| (!$accessAlreadyChecked && !$this->templateRightService->canView($userId, $templateId))
		)
		{
			return null;
		}
		if ($template->replicateParams === null)
		{
			return null;
		}

		return [
			'taskId' => $template->taskId,
			'replicate' => $template->replicate,
			'regularityType' => $template->regularityType?->value,
			'replicateParams' => $template->replicateParams?->toArray(),
			'replicateCount' => $template->replicateCount,
			'deadlineAfter' => $template->deadlineAfter,
			'matchesWorkTime' => $template->matchesWorkTime,
		];
	}
}

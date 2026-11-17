<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\Recurrence;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\DtoValidationException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\Recurrence\GetRegularTaskMetricsDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder\TaskSchemaBuilder;
use Bitrix\Tasks\V2\Internal\Integration\Intranet\Service\ToolService;
use Bitrix\Tasks\V2\Internal\Service\Replication\Recurrence\RecurrenceRuleService;
use Bitrix\Tasks\V2\Internal\Service\Replication\Recurrence\RecurrenceTemplateResolver;
use Bitrix\Tasks\V2\Internal\Service\Replication\Schedule\LoadAggregateService;
use Bitrix\Tasks\V2\Internal\Service\Replication\Section\SectionVisibilityService;
use Throwable;

class GetRegularTaskMetricsTool extends BaseTemplateRecurrenceTool
{
	public const ACTION_NAME = 'get_regular_task_metrics';

	public function __construct(
		private readonly SectionVisibilityService $sectionVisibilityService,
		private readonly LoadAggregateService $loadAggregateService,
		private readonly RecurrenceRuleService $recurrenceRuleService,
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
			'Returns one combined buckets and grandTotal provisioning count '
			. 'for up to ' . GetRegularTaskMetricsDto::MAX_ITEMS . ' readable recurring task templates or tasks '
			. 'over an interval of up to ' . GetRegularTaskMetricsDto::MAX_INTERVAL_DAYS . ' days. '
			. 'The scale must split the interval into at most '
			. GetRegularTaskMetricsDto::MAX_BUCKETS . ' buckets, and buckets multiplied by the number '
			. 'of identifiers must not exceed ' . GetRegularTaskMetricsDto::MAX_BUCKET_TEMPLATE_PAIRS . '. '
			. 'Inaccessible or unresolved identifiers are excluded. '
			. 'Submit either one templateIds batch or one taskIds batch.'
		;
	}

	public function getInputSchema(): array
	{
		$identifierSchema = GetRegularTaskMetricsDto::getInputSchema();

		return [
			'type' => 'object',
			'properties' => [
				...$identifierSchema['properties'],
				'scale' => [
					'type' => 'string',
					'description' =>
						'Bucket size for the combined provisioning count. '
						. 'Choose a coarser size for a longer interval: the interval must fit into '
						. GetRegularTaskMetricsDto::MAX_BUCKETS . ' buckets.'
					,
					'enum' => GetRegularTaskMetricsDto::ALLOWED_SCALES,
				],
				'from' => [
					'type' => 'integer',
					'description' =>
						'Inclusive start of the interval as a Unix timestamp in seconds. '
						. 'A non-inverted interval must not exceed '
						. GetRegularTaskMetricsDto::MAX_INTERVAL_DAYS . ' days, and its timezone-adjusted '
						. 'timestamps must stay within years 0001 to 9999.'
					,
				],
				'to' => [
					'type' => 'integer',
					'description' =>
						'Inclusive end of the interval as a Unix timestamp in seconds. '
						. 'If earlier than from, the result is empty. '
						. 'Otherwise, the interval must not exceed '
						. GetRegularTaskMetricsDto::MAX_INTERVAL_DAYS . ' days, and its timezone-adjusted '
						. 'timestamps must stay within years 0001 to 9999.'
					,
				],
				'tzOffset' => [
					'type' => 'integer',
					'description' =>
						'Viewer UTC offset in seconds, added to UTC when building bucket dates '
						. '(for example, 10800 for UTC+03:00). '
						. 'Use a value from -43200 (UTC-12:00) to 50400 (UTC+14:00).'
					,
					'minimum' => GetRegularTaskMetricsDto::MIN_TZ_OFFSET,
					'maximum' => GetRegularTaskMetricsDto::MAX_TZ_OFFSET,
				],
			],
			'required' => ['scale', 'from', 'to', 'tzOffset'],
			'oneOf' => $identifierSchema['oneOf'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		try
		{
			$dto = GetRegularTaskMetricsDto::fromArray($args);
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
			$recurringTemplateIds = array_values(array_diff($templateIds, $idsWithoutRule));
			$readableIds = $dto->usesTaskIds()
				? $recurringTemplateIds
				: $this->sectionVisibilityService->filterReadableInSection($recurringTemplateIds, $userId)
			;

			return $this->loadAggregateService->getAggregate(
				viewerId: $userId,
				sliceTemplateIds: $readableIds,
				scale: $dto->scale,
				from: $dto->from,
				to: $dto->to,
				tzOffset: $dto->tzOffset,
			);
		}
		catch (Throwable $e)
		{
			throw $this->createServiceFailureException($e);
		}
	}
}

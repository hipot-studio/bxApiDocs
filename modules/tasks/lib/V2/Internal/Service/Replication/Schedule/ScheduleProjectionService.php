<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Replication\Schedule;

use Bitrix\Tasks\Internals\Task\Template\TemplateObject;
use Bitrix\Tasks\Internals\Task\TemplateTable;
use Bitrix\Tasks\Replication\Template\Enum\RegularityType;
use Bitrix\Tasks\Replication\Template\Time\Service\ScheduleSequenceService;
use Bitrix\Tasks\V2\Internal\Dto\Replication\ScheduleCellDto;
use Bitrix\Tasks\V2\Internal\Repository\Replication\InstanceFactRepository;
use Bitrix\Tasks\V2\Internal\Repository\Replication\ReplicationErrorRepository;
use Bitrix\Tasks\V2\Internal\Service\Replication\AfterCompletionPlanService;

/**
 * Builds schedule cells for the visible rows of the current period (DTO-S2, P2.T2).
 *
 * The past = fact ({@see InstanceFactRepository}), the future = plan
 * ({@see ScheduleSequenceService}). For `after_completion`, only an already armed
 * transition is known; all other future buckets remain `na`. Fact and plan are
 * bucketized in the same timezone (AC-S-062, AC-S-068).
 *
 * Fact and errors for the whole slice are read with two grouped queries over the entire
 * templateId set (not a query-per-row) and distributed across rows. There is no cache on
 * purpose: the counters are dynamic - a new fork or an instance error "today" changes them
 * outside invalidation events, while grouping over the slice already fits a fixed number of queries.
 */
class ScheduleProjectionService
{
	/**
	 * Compatibility tag prefix retained for batch cache invalidation callers.
	 * Schedule facts are not cached because task access may change independently.
	 */
	public const CACHE_TAG_PREFIX = 'replication_projection';

	private readonly AfterCompletionPlanService $afterCompletionPlanService;

	public function __construct(
		private readonly ScheduleSequenceService $sequenceService,
		private readonly InstanceFactRepository $instanceFactRepository,
		private readonly ReplicationErrorRepository $errorRepository,
		private readonly PeriodBucketer $bucketer = new PeriodBucketer(),
		?AfterCompletionPlanService $afterCompletionPlanService = null,
	)
	{
		$this->afterCompletionPlanService = $afterCompletionPlanService ?? new AfterCompletionPlanService();
	}

	/**
	 * @param int[] $templateIds visible rows of the current page
	 * @return ScheduleCellDto[]
	 */
	public function getCells(
		int $viewerId,
		array $templateIds,
		string $scale,
		int $from,
		int $to,
		int $tzOffset,
	): array
	{
		if (!PeriodBucketer::isValidScale($scale))
		{
			return [];
		}

		$templateIds = array_values(array_unique(array_map('intval', $templateIds)));
		$templates = $this->loadTemplates($templateIds);
		if ($templates === [])
		{
			return [];
		}

		// Fact and errors for the whole slice - two grouped queries (not N+1): the repositories
		// return counters already grouped by templateId and bucket.
		$factByTemplate = $this->instanceFactRepository
			->countFactByTemplateAndBucket($viewerId, $templateIds, $from, $to, $scale, $tzOffset)
		;
		$errorByTemplate = $this->errorRepository
			->countErrorsByTemplateAndBucket($templateIds, $from, $to, $scale, $tzOffset)
		;
		$afterCompletionPlan = $this->afterCompletionPlanService->getNextExecutionTimes(
			$this->getAfterCompletionTemplateIds($templates),
		);

		$cells = [];
		foreach ($templateIds as $templateId)
		{
			$template = $templates[$templateId] ?? null;
			if ($template === null)
			{
				continue;
			}

			$cells[] = new ScheduleCellDto(
				templateId: $templateId,
				period: $scale,
				buckets: $this->buildBuckets(
					$template,
					$scale,
					$from,
					$to,
					$tzOffset,
					$factByTemplate[$templateId] ?? [],
					$errorByTemplate[$templateId] ?? [],
					$afterCompletionPlan[$templateId] ?? null,
				),
			);
		}

		return $cells;
	}

	/**
	 * Fact per bucket ($factByKey) split into "completed"/"total" (Q-2, created = total -
	 * completed) and the error count ($errorByKey) - already fetched with a grouped query over
	 * the whole slice in {@see self::getCells()}; here only distribution plus the plan projection.
	 *
	 * @param array<string, array{total:int, completed:int}> $factByKey
	 * @param array<string, int> $errorByKey
	 * @return array<int, array{key:string, total:int, completed:int, created:int, planned:int, error:int, na:bool, isToday:bool}>
	 */
	private function buildBuckets(
		TemplateObject $template,
		string $scale,
		int $from,
		int $to,
		int $tzOffset,
		array $factByKey,
		array $errorByKey,
		?int $afterCompletionPlanTs,
	): array
	{
		$keys = $this->bucketer->buildBucketKeys($scale, $from, $to, $tzOffset);
		if ($keys === [])
		{
			return [];
		}

		$isAfterCompletion = (
			RegularityType::fromValue($template->getReplicateType()) === RegularityType::AfterCompletion
		);
		// A paused rule produces nothing from now on: its cells carry the fact alone, and the
		// future stays empty rather than unknown - there is no schedule left to wait for.
		$hasPlan = (bool)$template->getReplicate();

		$todayKey = $this->bucketer->bucketKeyForTimestamp($scale, time(), $tzOffset);
		$planByKey = $hasPlan
			? $this->buildPlanByKey($template, $scale, $from, $to, $tzOffset, $afterCompletionPlanTs)
			: []
		;

		$buckets = [];
		foreach ($keys as $key)
		{
			$isFutureBucket = ($todayKey !== null && $key > $todayKey);

			$factTotal = (int)($factByKey[$key]['total'] ?? 0);
			$completed = (int)($factByKey[$key]['completed'] ?? 0);
			$created = max(0, $factTotal - $completed);
			$planned = (int)($planByKey[$key] ?? 0);

			$buckets[] = [
				'key' => $key,
				'total' => $completed + $created + $planned,
				'completed' => $completed,
				'created' => $created,
				'planned' => $planned,
				'error' => (int)($errorByKey[$key] ?? 0),
				// An armed after_completion transition is planned; later ones remain unknown.
				'na' => $hasPlan && $isAfterCompletion && $isFutureBucket && $planned === 0,
				'isToday' => ($key === $todayKey),
			];
		}

		return $buckets;
	}

	/**
	 * Plan projection of an active rule over the remaining window: the event-driven type
	 * contributes only its already armed transition, the scheduled ones - their whole sequence.
	 *
	 * @return array<string, int> [bucketKey => planned]
	 */
	private function buildPlanByKey(
		TemplateObject $template,
		string $scale,
		int $from,
		int $to,
		int $tzOffset,
		?int $afterCompletionPlanTs,
	): array
	{
		$planFrom = max($from, time());

		if (RegularityType::fromValue($template->getReplicateType()) === RegularityType::AfterCompletion)
		{
			if ($afterCompletionPlanTs === null || $afterCompletionPlanTs < $planFrom || $afterCompletionPlanTs > $to)
			{
				return [];
			}

			$key = $this->bucketer->bucketKeyForTimestamp($scale, $afterCompletionPlanTs, $tzOffset);

			return $key === null ? [] : [$key => 1];
		}

		$planByKey = [];
		foreach ($this->sequenceService->getSequence($template, $planFrom, $to) as $planTs)
		{
			$key = $this->bucketer->bucketKeyForTimestamp($scale, $planTs, $tzOffset);
			if ($key !== null)
			{
				$planByKey[$key] = ($planByKey[$key] ?? 0) + 1;
			}
		}

		return $planByKey;
	}

	/**
	 * @param array<int, TemplateObject> $templates
	 * @return int[]
	 */
	private function getAfterCompletionTemplateIds(array $templates): array
	{
		$result = [];
		foreach ($templates as $templateId => $template)
		{
			if (
				$template->getReplicate()
				&& RegularityType::fromValue($template->getReplicateType()) === RegularityType::AfterCompletion
			)
			{
				$result[] = (int)$templateId;
			}
		}

		return $result;
	}

	/**
	 * @param int[] $templateIds
	 * @return array<int, TemplateObject>
	 */
	private function loadTemplates(array $templateIds): array
	{
		if ($templateIds === [])
		{
			return [];
		}

		$collection = TemplateTable::query()
			->setSelect(['*'])
			->whereIn('ID', $templateIds)
			->exec()
			->fetchCollection()
		;

		$result = [];
		foreach ($collection as $template)
		{
			$result[(int)$template->getId()] = $template;
		}

		return $result;
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Replication\Schedule;

use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\Internals\Task\Template\TemplateObject;
use Bitrix\Tasks\Internals\Task\TemplateTable;
use Bitrix\Tasks\Replication\Template\Enum\RegularityType;
use Bitrix\Tasks\Replication\Template\Time\Service\ScheduleSequenceService;
use Bitrix\Tasks\V2\Internal\Repository\Replication\InstanceFactRepository;
use Bitrix\Tasks\V2\Internal\Service\Replication\AfterCompletionPlanService;

/**
 * Exact load aggregate for a bounded regular-task slice (ALG-S2, API-S4, P2.T4).
 *
 * Facts are fetched by one grouped COUNT query for the whole slice.
 * The plan uses {@see ScheduleSequenceService}, preserving sequence phase, START_DATE,
 * NEXT_EXECUTION_TIME, END_DATE, REPEAT_TILL and TIMES. Event-driven after_completion
 * contributes only its already armed transition; later dates remain unknown.
 *
 * The public entry point limits the slice to 50 unique ACL-visible templates before
 * this service is called. The result is not cached because facts depend on task access
 * without versioned invalidation.
 */
class LoadAggregateService
{
	private const DAY_SECONDS = 86400;

	private readonly AfterCompletionPlanService $afterCompletionPlanService;

	public function __construct(
		private readonly InstanceFactRepository $instanceFactRepository,
		private readonly ScheduleSequenceService $sequenceService = new ScheduleSequenceService(),
		private readonly PeriodBucketer $bucketer = new PeriodBucketer(),
		?AfterCompletionPlanService $afterCompletionPlanService = null,
	)
	{
		$this->afterCompletionPlanService = $afterCompletionPlanService ?? new AfterCompletionPlanService();
	}

	/**
	 * @param int[] $sliceTemplateIds
	 * @return array{buckets: array<int, array{key:string, total:int}>, grandTotal: int}
	 */
	public function getAggregate(
		int $viewerId,
		array $sliceTemplateIds,
		string $scale,
		int $from,
		int $to,
		int $tzOffset,
	): array
	{
		if (!PeriodBucketer::isValidScale($scale))
		{
			return ['buckets' => [], 'grandTotal' => 0];
		}

		Collection::normalizeArrayValuesByInt($sliceTemplateIds, false);
		$keys = $this->bucketer->buildBucketKeys($scale, $from, $to, $tzOffset);
		if ($sliceTemplateIds === [] || $keys === [])
		{
			return ['buckets' => [], 'grandTotal' => 0];
		}

		$lastBucketKey = $keys[array_key_last($keys)];
		$lastBucketRange = $this->bucketer->getBucketRange($scale, $lastBucketKey, $tzOffset);
		$effectiveTo = $lastBucketRange === null
			? $to
			: min($to, $lastBucketRange['to'] - 1)
		;

		return $this->build($viewerId, $sliceTemplateIds, $scale, $from, $effectiveTo, $tzOffset, $keys);
	}

	/**
	 * @param int[] $sliceTemplateIds
	 * @param string[] $keys
	 * @return array{buckets: array<int, array{key:string, total:int}>, grandTotal: int}
	 */
	private function build(
		int $viewerId,
		array $sliceTemplateIds,
		string $scale,
		int $from,
		int $to,
		int $tzOffset,
		array $keys,
	): array
	{
		$totals = array_fill_keys($keys, 0);

		// FACT - one grouped query.
		foreach (
			$this->instanceFactRepository->countByBucket(
				$viewerId,
				$sliceTemplateIds,
				$from,
				$to,
				$scale,
				$tzOffset,
			) as $key => $count
		)
		{
			if (isset($totals[$key]))
			{
				$totals[$key] += $count;
			}
		}

		// Project only the remaining window; past occurrences are already represented by facts.
		$planFrom = max($from, time());
		if ($planFrom <= $to)
		{
			$sequenceLimit = max(1, intdiv($to - $planFrom, self::DAY_SECONDS) + 2);
			// A paused rule keeps only its facts in the load: nothing of it is planned any more.
			$activeTemplates = array_filter(
				$this->loadTemplates($sliceTemplateIds),
				static fn (TemplateObject $template): bool => (bool)$template->getReplicate(),
			);
			$afterCompletionTemplateIds = [];
			foreach ($activeTemplates as $template)
			{
				if (RegularityType::fromValue($template->getReplicateType()) === RegularityType::AfterCompletion)
				{
					$afterCompletionTemplateIds[] = (int)$template->getId();
				}
			}
			$afterCompletionPlan = $this->afterCompletionPlanService
				->getNextExecutionTimes($afterCompletionTemplateIds)
			;

			foreach ($activeTemplates as $template)
			{
				if (RegularityType::fromValue($template->getReplicateType()) === RegularityType::AfterCompletion)
				{
					$planTs = $afterCompletionPlan[(int)$template->getId()] ?? null;
					if ($planTs !== null && $planTs >= $planFrom && $planTs <= $to)
					{
						$key = $this->bucketer->bucketKeyForTimestamp($scale, $planTs, $tzOffset);
						if ($key !== null && isset($totals[$key]))
						{
							$totals[$key]++;
						}
					}

					continue;
				}

				foreach (
					$this->sequenceService->getSequence($template, $planFrom, $to, $sequenceLimit)
					as $planTs
				)
				{
					$key = $this->bucketer->bucketKeyForTimestamp($scale, $planTs, $tzOffset);
					if ($key !== null && isset($totals[$key]))
					{
						$totals[$key]++;
					}
				}
			}
		}

		$buckets = [];
		$grandTotal = 0;
		foreach ($keys as $key)
		{
			$total = (int)$totals[$key];
			$buckets[] = ['key' => $key, 'total' => $total];
			$grandTotal += $total;
		}

		return ['buckets' => $buckets, 'grandTotal' => $grandTotal];
	}

	/**
	 * @param int[] $templateIds
	 * @return TemplateObject[]
	 */
	private function loadTemplates(array $templateIds): array
	{
		if ($templateIds === [])
		{
			return [];
		}

		return TemplateTable::query()
			->setSelect([
				'ID',
				'CREATED_BY',
				'REPLICATE',
				'REPLICATE_TYPE',
				'REPLICATE_PARAMS',
				'TPARAM_REPLICATION_COUNT',
			])
			->whereIn('ID', $templateIds)
			->exec()
			->fetchCollection()
			->getAll()
		;
	}
}

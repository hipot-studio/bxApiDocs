<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Replication;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\V2\Infrastructure\Controller\ActionFilter\Rule\RegularTasksSectionEnabled;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Dto\Replication\ScheduleCellDto;
use Bitrix\Tasks\V2\Internal\Service\Replication\Schedule\LoadAggregateService;
use Bitrix\Tasks\V2\Internal\Service\Replication\Schedule\PeriodColumns;
use Bitrix\Tasks\V2\Internal\Service\Replication\Schedule\ScheduleProjectionService;
use Bitrix\Tasks\V2\Internal\Service\Replication\Section\ReplicationRowService;
use Bitrix\Tasks\V2\Internal\Service\Replication\Section\SectionActivity;
use Bitrix\Tasks\V2\Internal\Service\Replication\Section\SectionListQuery;
use Bitrix\Tasks\V2\Internal\Service\Replication\Section\SectionRole;
use Bitrix\Tasks\V2\Internal\Service\Replication\Section\SectionVisibilityService;

/**
 * Schedule projection and the "Total" aggregate of the "Regular tasks" section (API-S3, API-S4).
 *
 * `get` - schedule cells for the visible rows of the current page (P2.T3).
 * `getLoad` - total load over the whole slice, not the page (P2.T4), or over an explicitly
 * requested set of templates.
 */
class Schedule extends BaseController
{
	public const ERROR_TEMPLATE_LIMIT_EXCEEDED = 'TASKS_REGULAR_SCHEDULE_TEMPLATE_LIMIT_EXCEEDED';

	private const MAX_TEMPLATE_IDS = 50;
	private const MAX_TEMPLATE_ID_ARGUMENTS = 500;

	/**
	 * @ajaxAction tasks.V2.Replication.Schedule.get
	 */
	#[RegularTasksSectionEnabled]
	#[CloseSession]
	public function getAction(
		ScheduleProjectionService $projectionService,
		SectionVisibilityService $visibilityService,
		PeriodColumns $periodColumns,
		array $templateIds = [],
		string $period = PeriodColumns::WEEK,
		int $anchorTs = 0,
		int $tzOffset = 0,
	): ?array
	{
		// Defensive: projection only for rows visible to the user in the section (P1.T2).
		$visibleIds = $this->resolveVisibleTemplateIds($templateIds, $visibilityService);
		if ($visibleIds === null)
		{
			return null;
		}

		if ($visibleIds === [])
		{
			return ['cells' => []];
		}

		// Window/scale via the single PER-01 resolver: projection buckets match the grid
		// columns (same keys), so the cell frontend overlay finds its data.
		$window = $periodColumns->resolve($period, $anchorTs, $tzOffset);

		$cells = $projectionService->getCells(
			$this->userId,
			$visibleIds,
			$window['scale'],
			$window['from'],
			$window['to'],
			$tzOffset,
		);

		return [
			'cells' => array_map(static fn (ScheduleCellDto $cell): array => $cell->toArray(), $cells),
		];
	}

	/**
	 * @ajaxAction tasks.V2.Replication.Schedule.getLoad
	 */
	#[RegularTasksSectionEnabled]
	#[CloseSession]
	public function getLoadAction(
		LoadAggregateService $loadAggregateService,
		ReplicationRowService $rowService,
		PeriodColumns $periodColumns,
		SectionVisibilityService $visibilityService,
		array $templateIds = [],
		string $period = PeriodColumns::WEEK,
		int $anchorTs = 0,
		int $tzOffset = 0,
		?string $roleId = null,
		?bool $replicateActive = null,
		?string $replicateStatus = null,
		array $filter = [],
	): ?array
	{
		if ($templateIds === [])
		{
			// "Total" is calculated over the whole slice, not only the visible page.
			// LIMIT+1 applies after DISTINCT in the ACL query, so duplicate joins and forAll
			// grants cannot hide the 51st unique template.
			$sliceTemplateIds = $rowService->getSliceTemplateIds(
				new SectionListQuery(
					viewerId: $this->userId,
					roleFilter: SectionRole::resolveMemberType($roleId),
					replicateActive: SectionActivity::resolve($replicateStatus, $replicateActive ?? true),
					filter: SectionFilter::normalize($filter),
				),
				self::MAX_TEMPLATE_IDS + 1,
			);
			if (count($sliceTemplateIds) > self::MAX_TEMPLATE_IDS)
			{
				$this->addTemplateLimitError();

				return null;
			}
		}
		else
		{
			// An explicit request replaces the section slice: it is never widened back to it,
			// even when nothing survives normalization or the visibility filter.
			$sliceTemplateIds = $this->resolveVisibleTemplateIds($templateIds, $visibilityService);
			if ($sliceTemplateIds === null)
			{
				return null;
			}
		}

		// The same PER-01 window as the projection and columns.
		$window = $periodColumns->resolve($period, $anchorTs, $tzOffset);

		return $loadAggregateService->getAggregate(
			$this->userId,
			$sliceTemplateIds,
			$window['scale'],
			$window['from'],
			$window['to'],
			$tzOffset,
		);
	}

	/**
	 * Both limits are checked before any data access; null means the request is rejected.
	 *
	 * @param mixed[] $templateIds
	 * @return int[]|null
	 */
	private function resolveVisibleTemplateIds(
		array $templateIds,
		SectionVisibilityService $visibilityService,
	): ?array
	{
		if (count($templateIds) > self::MAX_TEMPLATE_ID_ARGUMENTS)
		{
			$this->addTemplateLimitError();

			return null;
		}

		$normalizedIds = $this->normalizeTemplateIds($templateIds);
		if (count($normalizedIds) > self::MAX_TEMPLATE_IDS)
		{
			$this->addTemplateLimitError();

			return null;
		}

		return $visibilityService->filterReadableInSection($normalizedIds, $this->userId);
	}

	/**
	 * @param mixed[] $templateIds
	 * @return int[]
	 */
	private function normalizeTemplateIds(array $templateIds): array
	{
		$ids = [];

		foreach ($templateIds as $templateId)
		{
			$id = filter_var(
				$templateId,
				FILTER_VALIDATE_INT,
				['options' => ['min_range' => 1]],
			);
			if ($id === false)
			{
				continue;
			}

			$ids[$id] = $id;
			if (count($ids) > self::MAX_TEMPLATE_IDS)
			{
				break;
			}
		}

		return array_values($ids);
	}

	private function addTemplateLimitError(): void
	{
		$this->addError(
			new Error(
				(string)Loc::getMessage(
					'TASKS_V2_REPLICATION_SCHEDULE_TEMPLATE_LIMIT_EXCEEDED',
					['#LIMIT#' => self::MAX_TEMPLATE_IDS],
				),
				self::ERROR_TEMPLATE_LIMIT_EXCEEDED,
			),
		);
	}
}

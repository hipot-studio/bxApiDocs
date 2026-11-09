<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Dashboard;

use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Dashboard\DashboardJsonMetadataReader;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Dashboard\DashboardLayoutBuilder;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Dashboard\NativeFilterBuilder;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Replaces the chart layout and/or the filter configuration of a custom dashboard the actor may edit,
 * with the checks and texts of set_dashboard_content. Every dashboard keeps a `time_range` filter,
 * and filters left as they are follow the new charts (rescopeChartFilters()).
 */
final class SetContentOperation
{
	public const MAX_CHARTS = 50;
	public const MAX_FILTERS = 20;

	private const RESCOPABLE_FILTER_TYPES = ['filter_time', 'filter_select', 'filter_range'];

	private readonly DashboardGuard $guard;
	private readonly DraftOnlyRule $draftRule;

	public function __construct(?DashboardGuard $guard = null, ?DraftOnlyRule $draftRule = null)
	{
		$this->guard = $guard ?? new DashboardGuard();
		$this->draftRule = $draftRule ?? new DraftOnlyRule();
	}

	/**
	 * The replace as it would happen, checked to the end, without writing anything.
	 *
	 * Data: `id`, `name`, `charts_count` and `filters_count`, null for a part that is kept.
	 */
	public function preview(SetContentCommand $command, Actor $actor, DraftPolicy $policy): Result
	{
		$prepared = $this->prepare($command, $actor, $policy);
		if (!$prepared->isSuccess())
		{
			return $prepared;
		}

		return (new Result())->setData([
			'id' => $command->dashboardId,
			'name' => (string)$prepared->getData()['dashboard']->getTitle(),
			'charts_count' => $command->charts === null ? null : count($command->charts),
			'filters_count' => $command->filters === null ? null : count($command->filters),
		]);
	}

	/**
	 * Data: the answer of set_dashboard_content — `id`, `name`, `published`, `charts_count`, `filters_count`.
	 */
	public function execute(SetContentCommand $command, Actor $actor, DraftPolicy $policy): Result
	{
		$prepared = $this->prepare($command, $actor, $policy);
		if (!$prepared->isSuccess())
		{
			return $prepared;
		}

		/** @var \Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboard $dashboard */
		$dashboard = $prepared->getData()['dashboard'];
		$externalId = (int)$dashboard->getExternalId();
		$hasCharts = $command->charts !== null;
		$hasFilters = $command->filters !== null;

		$draft = $this->draftRule->recheck(
			$command->dashboardId,
			$policy,
			$prepared->getData()['publishedInSuperset'],
		);
		if (!$draft->isSuccess())
		{
			return $draft;
		}

		$response = IntegratorFactory::getInstance()->updateDashboard($externalId, [
			'json_metadata' => $prepared->getData()['jsonMetadata'],
		]);
		if ($response->hasErrors())
		{
			return Refusal::unavailableBi(
				$response->getErrors(),
				[
					'stage' => 'set_dashboard_content',
					'user_id' => $actor->userId,
					'dashboard_id' => $command->dashboardId,
					'external_id' => $externalId,
					'changed_keys' => array_values(array_filter([
						$hasCharts ? 'charts' : null,
						$hasFilters ? 'filters' : null,
					])),
				],
			);
		}

		return (new Result())->setData([
			'id' => $command->dashboardId,
			'name' => (string)$dashboard->getTitle(),
			'published' => $dashboard->getStatus() === SupersetDashboardTable::DASHBOARD_STATUS_READY,
			'charts_count' => $hasCharts ? count($command->charts) : null,
			'filters_count' => $hasFilters ? count($command->filters) : null,
		]);
	}

	/**
	 * Every check of the replace and the json_metadata it would send.
	 *
	 * Data: `dashboard`, `jsonMetadata`, `publishedInSuperset`.
	 */
	private function prepare(SetContentCommand $command, Actor $actor, DraftPolicy $policy): Result
	{
		$result = new Result();
		$dashboardId = $command->dashboardId;
		$hasCharts = $command->charts !== null;
		$hasFilters = $command->filters !== null;

		if (!$hasCharts && !$hasFilters)
		{
			return $result->addError(new Error(
				'Nothing to update. Provide at least one of: charts, filters. '
				. 'For rename use update_dashboard_metadata; for publish/unpublish use '
				. 'publish_dashboard / unpublish_dashboard.',
				FailureCode::InvalidArgument->value,
			));
		}

		$tooMany = match (true)
		{
			$hasCharts && count($command->charts) > self::MAX_CHARTS =>
				'charts must contain at most ' . self::MAX_CHARTS . ' items.',
			$hasFilters && count($command->filters) > self::MAX_FILTERS =>
				'filters must contain at most ' . self::MAX_FILTERS . ' items.',
			default => null,
		};
		if ($tooMany !== null)
		{
			return $result->addError(new Error($tooMany, FailureCode::InvalidArgument->value));
		}

		$loaded = $this->guard->forWrite($dashboardId, $actor);
		if (!$loaded->isSuccess())
		{
			return $loaded;
		}
		$dashboard = $loaded->getData()['dashboard'];

		$draft = $this->draftRule->check($dashboard, $policy);
		if (!$draft->isSuccess())
		{
			return $draft;
		}

		$timeRangeProvided = $hasFilters && self::filtersContainTimeRange($command->filters);
		if ($hasFilters && !$timeRangeProvided)
		{
			return $result->addError(new Error(
				'filters must contain at least one `time_range` filter with a non-empty '
				. '`defaultValue`. Every dashboard needs a date filter; if the user did '
				. 'not specify a period, default to "Last quarter".',
				FailureCode::InvalidArgument->value,
			));
		}

		// Superset replaces json_metadata wholesale and syncs the charts of a dashboard only from the
		// positions inside it, so the new parts are merged onto the dict it holds now. Without that dict
		// nothing is written: the merge would wipe what the dashboard has.
		$remote = DashboardJsonMetadataReader::fetchDashboard(
			(int)$dashboard->getExternalId(),
			$actor->userId,
			$dashboardId,
			'set_dashboard_content',
		);
		if ($remote === null)
		{
			return Refusal::unavailableBi(
				[new Error('Current json_metadata of the dashboard could not be read')],
				[
					'stage' => 'set_dashboard_content.fetch_metadata',
					'user_id' => $actor->userId,
					'dashboard_id' => $dashboardId,
				],
			);
		}
		$jsonMetadata = $remote->jsonMetadata;

		if ($hasCharts && !$timeRangeProvided && !DashboardJsonMetadataReader::hasTimeRange($jsonMetadata))
		{
			return $result->addError(new Error(
				'Dashboard has no `time_range` filter configured. Every dashboard '
				. 'must have a date filter before charts are attached. Pass '
				. '`filters` with at least one `time_range` entry (use '
				. '"Last quarter" as default if the user did not specify a '
				. 'period).',
				// The remedy is in the arguments, so a REST caller is told it as a validation message.
				FailureCode::InvalidArgument->value,
			));
		}

		if (!array_key_exists('cross_filters_enabled', $jsonMetadata))
		{
			$jsonMetadata['cross_filters_enabled'] = true;
		}

		$savedPositions = DashboardJsonMetadataReader::savedPositions($remote);
		// The rescope compares a filter with the old chart set.
		$oldChartIds = self::extractChartIdsFromPositions($savedPositions);

		// The builders refuse a malformed part with an exception of the caller's choice, here one of our own.
		$refusal = static fn(string $message): \Throwable => new ContentRefusedException($message);
		try
		{
			if ($hasCharts)
			{
				$jsonMetadata['positions'] = DashboardLayoutBuilder::build($command->charts, $refusal);
			}

			if ($hasFilters)
			{
				// Filters without their own chartIds apply to every chart of this update, or of the layout kept.
				$defaultChartsInScope = $hasCharts
					? array_map(
						static fn (array $c): int => (int)($c['chartId'] ?? 0),
						$command->charts,
					)
					: $oldChartIds
				;
				$jsonMetadata['native_filter_configuration'] = NativeFilterBuilder::build(
					$command->filters,
					$defaultChartsInScope,
					$refusal,
				);
			}
			elseif ($hasCharts && isset($jsonMetadata['native_filter_configuration']))
			{
				$jsonMetadata['native_filter_configuration'] = self::rescopeChartFilters(
					$jsonMetadata['native_filter_configuration'],
					$oldChartIds,
					array_map(
						static fn (array $c): int => (int)($c['chartId'] ?? 0),
						$command->charts,
					),
				);
			}
		}
		catch (ContentRefusedException $exception)
		{
			return $result->addError(new Error($exception->getMessage(), FailureCode::InvalidArgument->value));
		}

		$access = self::checkContentAccess(
			$command->charts,
			$command->filters,
			$hasCharts ? self::extractChartIdsFromPositions($jsonMetadata['positions']) : $oldChartIds,
			$actor,
			$oldChartIds,
		);
		if (!$access->isSuccess())
		{
			return $access;
		}

		return $result->setData([
			'dashboard' => $dashboard,
			'jsonMetadata' => $jsonMetadata,
			'publishedInSuperset' => $remote->published,
		]);
	}

	/**
	 * Checks only supplied references against the validated final layout, leaving saved links alone.
	 *
	 * @param int[] $savedChartIds Charts of the saved layout: allowed by the EDIT of the target, not read.
	 */
	public static function checkContentAccess(
		?array $charts,
		?array $filters,
		array $layoutChartIds,
		Actor $actor,
		array $savedChartIds = [],
	): Result
	{
		$chartIds = [];
		foreach ($charts ?? [] as $chart)
		{
			$chartIds[] = $chart['chartId'];
		}

		$layout = array_fill_keys($layoutChartIds, true);
		foreach ($filters ?? [] as $filter)
		{
			foreach ($filter['chartIds'] ?? [] as $id)
			{
				if (!isset($layout[(int)$id]))
				{
					return Refusal::error(
						FailureCode::InvalidArgument,
						'filters[].chartIds must reference charts in the dashboard layout.',
					);
				}
				$chartIds[] = $id;
			}
		}

		return (new ContentAccessPreflight())->check($chartIds, $actor, $savedChartIds);
	}

	/**
	 * Whether the filters have a `time_range` one with a non-blank `defaultValue`.
	 */
	public static function filtersContainTimeRange(array $filters): bool
	{
		foreach ($filters as $filter)
		{
			if (!is_array($filter) || ($filter['filterType'] ?? '') !== 'time_range')
			{
				continue;
			}
			$defaultValue = isset($filter['defaultValue']) && is_string($filter['defaultValue'])
				? trim($filter['defaultValue'])
				: ''
			;
			if ($defaultValue !== '')
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @return int[]
	 */
	public static function extractChartIdsFromPositions(array $positions): array
	{
		$ids = [];
		foreach ($positions as $component)
		{
			if (!is_array($component) || ($component['type'] ?? '') !== 'CHART')
			{
				continue;
			}
			$id = (int)($component['meta']['chartId'] ?? 0);
			if ($id > 0)
			{
				$ids[] = $id;
			}
		}

		return array_values(array_unique($ids));
	}

	/**
	 * Realigns `chartsInScope` of every chart filter after the layout alone was replaced, keeping the intent
	 * the filter had against the OLD chart set:
	 *
	 *  - a filter on ALL the old charts (as a set) follows the NEW full set: new charts in, removed ones out;
	 *  - a filter narrowed by hand keeps its picks and only loses charts that are gone; it never gains new ones.
	 *
	 * Only `chartsInScope` is rewritten: NativeFilterBuilder always emits a global `scope`, and the Superset
	 * frontend reads the restriction from `chartsInScope`. Entries of other types are left as they are.
	 *
	 * @param array $config Existing native_filter_configuration.
	 * @param int[] $oldChartIds Chart IDs that were on the dashboard BEFORE the replace.
	 * @param int[] $newChartIds Chart IDs from the just-replaced layout.
	 */
	public static function rescopeChartFilters(array $config, array $oldChartIds, array $newChartIds): array
	{
		$oldFull = self::normalizeChartIdSet($oldChartIds);
		$newFull = self::normalizeChartIdSet($newChartIds);
		$newFullLookup = array_fill_keys($newFull, true);

		foreach ($config as &$entry)
		{
			if (!is_array($entry) || !in_array($entry['filterType'] ?? '', self::RESCOPABLE_FILTER_TYPES, true))
			{
				continue;
			}

			$oldScope = self::normalizeChartIdSet($entry['chartsInScope'] ?? []);

			if (self::sameChartIdSet($oldScope, $oldFull))
			{
				$entry['chartsInScope'] = $newFull;
			}
			else
			{
				$entry['chartsInScope'] = array_values(array_filter(
					$oldScope,
					static fn (int $id): bool => isset($newFullLookup[$id]),
				));
			}
		}
		unset($entry);

		return $config;
	}

	/**
	 * Unique positive chart IDs, reindexed.
	 *
	 * @return int[]
	 */
	private static function normalizeChartIdSet(mixed $ids): array
	{
		if (!is_array($ids))
		{
			return [];
		}

		return array_values(array_unique(array_filter(
			array_map('intval', $ids),
			static fn (int $id): bool => $id > 0,
		)));
	}

	/**
	 * Order-insensitive equality of two normalized chart-ID sets.
	 *
	 * @param int[] $a
	 * @param int[] $b
	 */
	private static function sameChartIdSet(array $a, array $b): bool
	{
		if (count($a) !== count($b))
		{
			return false;
		}

		$lookup = array_fill_keys($a, true);
		foreach ($b as $id)
		{
			if (!isset($lookup[$id]))
			{
				return false;
			}
		}

		return true;
	}
}

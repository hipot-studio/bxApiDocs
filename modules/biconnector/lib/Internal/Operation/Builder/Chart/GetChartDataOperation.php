<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Chart;

use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Filter\AppliedFilters;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Postprocess\DashboardDataTransformer;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Postprocess\TransformerConfig;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\UrlParameter\UrlParameters;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\ReadyDashboardGuard;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Row-level data of up to MAX_CHARTS_PER_CALL charts of a dashboard the actor may view, the answer
 * of get_chart_data: `dashboard`, `charts` with columns, rows, stats and paging, `applied_filters`,
 * and `missing_chart_ids` when some of the requested charts are not on the dashboard.
 *
 * Unlike the other chart reads, the right is checked on the dashboard: ACTION_BIC_DASHBOARD_VIEW through
 * ReadyDashboardGuard, the checks of BiToolBehaviour::loadReadyDashboard() over DashboardGuard::forView().
 * The arguments come resolved: pinned context is the business of an entry point. The shape of `appliedFilters`
 * is not checked here: get_chart_data takes a scalar `value` and wraps it, so an entry without the tool schema
 * checks the shape itself.
 */
final class GetChartDataOperation
{
	public const ROW_LIMIT_DEFAULT = 20;
	public const ROW_LIMIT_MAX = 300;
	public const MAX_CHARTS_PER_CALL = 5;

	private const CHART_FIELDS = [
		'id', 'name', 'kind', 'viz_label', 'tab_path', 'description',
		'column_descriptions', 'stats', 'slice_stable',
		'columns', 'data', 'offset', 'row_limit', 'total_rows', 'has_more',
	];

	private readonly ReadyDashboardGuard $guard;

	public function __construct(?ReadyDashboardGuard $guard = null)
	{
		$this->guard = $guard ?? new ReadyDashboardGuard();
	}

	/**
	 * Distinct ids of the requested charts under `chartIds` in the data, or the refusal of an empty or too long
	 * list. The REST entry calls it before the dashboard is loaded; the operation keeps its own call where
	 * get_chart_data refuses, after the dashboard.
	 */
	public static function resolveChartIds(array $chartIds): Result
	{
		if (empty($chartIds))
		{
			return Refusal::error(FailureCode::InvalidArgument, 'chartIds must be a non-empty array of chart ids.');
		}

		$requestedChartIds = [];
		foreach ($chartIds as $id)
		{
			$requestedChartIds[] = (int)$id;
		}
		$requestedChartIds = array_values(array_unique($requestedChartIds));

		if (count($requestedChartIds) > self::MAX_CHARTS_PER_CALL)
		{
			return Refusal::error(
				FailureCode::InvalidArgument,
				'Too many charts requested: max ' . self::MAX_CHARTS_PER_CALL . ' per call. '
				. 'Reduce `chartIds` to the most relevant charts for the user\'s question. '
				. 'If you must ask the user to narrow down, refer to charts BY THEIR `name` '
				. 'from the previous get_dashboard_meta response — never quote raw chart ids, '
				. 'they are an internal id and confuse the user.',
			);
		}

		return (new Result())->setData(['chartIds' => $requestedChartIds]);
	}

	public function execute(GetChartDataQuery $query, Actor $actor): Result
	{
		$userId = $actor->userId;
		$dashboardId = $query->dashboardId;

		$loadResult = $this->guard->forView($dashboardId, $actor);
		if (!$loadResult->isSuccess())
		{
			return $loadResult;
		}
		$dashboard = $loadResult->getData()['dashboard'];
		$externalId = (int)$dashboard->getExternalId();

		$rowLimit = max(1, min(self::ROW_LIMIT_MAX, $query->rowLimit));
		$offset = max(0, $query->offset);

		$sort = [];
		if ($query->sortBy !== null && $query->sortBy !== '')
		{
			$sort = [
				'by' => $query->sortBy,
				'order' => in_array($query->sortOrder, ['asc', 'desc'], true) ? $query->sortOrder : 'desc',
			];
		}

		$chartIdsResult = self::resolveChartIds($query->chartIds);
		if (!$chartIdsResult->isSuccess())
		{
			return $chartIdsResult;
		}
		$requestedChartIds = $chartIdsResult->getData()['chartIds'];

		$filters = new AppliedFilters($userId);
		// The dashboard config is needed even without caller filters: a dashboard with
		// its own period default in the builder must not get the portal period on top of it.
		$dashboardDtoResp = IntegratorFactory::getInstance()->getDashboardById($externalId);
		if ($dashboardDtoResp->hasErrors() || !$dashboardDtoResp->getData())
		{
			return Refusal::integratorFailure(
				$dashboardDtoResp,
				['stage' => 'get_chart_data.dashboard_metadata', 'dashboard_id' => $dashboardId, 'user_id' => $userId],
			);
		}
		$dashboardDto = $dashboardDtoResp->getData();
		if (!empty($query->appliedFilters))
		{
			$validateResult = $filters->validate($dashboardDto, $query->appliedFilters);
			if (!$validateResult->isSuccess())
			{
				return Refusal::invalidArgument($validateResult);
			}
		}
		$resolveResult = $filters->resolve($dashboard, $dashboardDto, $query->appliedFilters);
		if (!$resolveResult->isSuccess())
		{
			return Refusal::invalidArgument($resolveResult);
		}
		$appliedFilters = $resolveResult->getData()['filters'];
		$supersetFilters = $filters->convertToSupersetExtraFormData($appliedFilters);

		$urlParamsResult = (new UrlParameters($userId))->resolve($dashboardId, $query->urlParams);
		if (!$urlParamsResult->isSuccess())
		{
			return Refusal::invalidArgument($urlParamsResult);
		}

		$response = IntegratorFactory::getInstance()->getDashboardOverview(
			$externalId,
			$supersetFilters,
			$urlParamsResult->getData()['urlParams'],
			chartIds: $requestedChartIds,
			sort: $sort,
			limit: $rowLimit,
			offset: $offset,
		);
		if ($response->hasErrors())
		{
			return Refusal::integratorFailure(
				$response,
				['stage' => 'get_chart_data.overview', 'dashboard_id' => $dashboardId, 'user_id' => $userId],
			);
		}

		$rawData = $response->getData();
		if ($rawData === null)
		{
			return Refusal::unavailableDashboard(
				[new Error('Empty response from /overview')],
				['stage' => 'get_chart_data.overview_empty', 'dashboard_id' => $dashboardId, 'user_id' => $userId],
			);
		}

		$chartsById = [];
		foreach ($rawData['charts'] ?? [] as $chart)
		{
			$id = (int)($chart['id'] ?? 0);
			if ($id !== 0)
			{
				$chartsById[$id] = $chart;
			}
		}

		$missing = [];
		if (isset($rawData['missing_chart_ids']) && is_array($rawData['missing_chart_ids']))
		{
			foreach ($rawData['missing_chart_ids'] as $missingId)
			{
				$missing[] = (int)$missingId;
			}
		}

		$matchedCharts = [];
		foreach ($requestedChartIds as $chartId)
		{
			if (!isset($chartsById[$chartId]))
			{
				if (!in_array($chartId, $missing, true))
				{
					$missing[] = $chartId;
				}
				continue;
			}

			$chart = $chartsById[$chartId];

			if (isset($chart['error']) && is_string($chart['error']) && $chart['error'] !== '')
			{
				return Refusal::unavailableDashboard(
					[new Error($chart['error'])],
					[
						'stage' => 'get_chart_data.chart_error',
						'dashboard_id' => $dashboardId,
						'user_id' => $userId,
						'chart_id' => $chartId,
						'chart_name' => $chart['name'] ?? $chart['slice_name'] ?? '',
					],
				);
			}

			$matchedCharts[] = $chart;
		}

		if (empty($matchedCharts))
		{
			return Refusal::error(
				FailureCode::InvalidArgument,
				'None of the requested charts were found in dashboard ' . $dashboardId
				. ' (missing: ' . implode(', ', $missing) . ').',
			);
		}

		$humanFilters = [];
		foreach ($appliedFilters as $filter)
		{
			$humanFilters[] = [
				'name' => $filter['name'] ?? '',
				'value' => $filter['value'] ?? null,
			];
		}

		$config = new TransformerConfig();
		$config->appliedFilters = $humanFilters;
		$config->preserveChartIds = true;
		$config->chartFields = self::CHART_FIELDS;

		$result = (new DashboardDataTransformer($config))->transform([
			'dashboard' => [
				'id' => $dashboardId,
				'title' => (string)$dashboard->getTitle(),
			],
			'charts' => $matchedCharts,
			'filter_coverage' => $rawData['filter_coverage'] ?? [],
		]);

		if (!empty($missing))
		{
			$result['missing_chart_ids'] = $missing;
		}

		return (new Result())->setData($result);
	}
}

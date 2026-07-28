<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Filter\AppliedFilters;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Postprocess\DashboardDataTransformer;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Postprocess\TransformerConfig;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\UrlParameter\UrlParameters;
use Bitrix\Main\Loader;

final class GetChartDataTool extends BaseBiTool
{
	private const ROW_LIMIT_DEFAULT = 20;
	private const ROW_LIMIT_MAX = 300;
	private const MAX_CHARTS_PER_CALL = 5;

	private const CHART_FIELDS = [
		'id', 'name', 'kind', 'viz_label', 'tab_path', 'description',
		'column_descriptions', 'stats', 'slice_stable',
		'columns', 'data', 'offset', 'row_limit', 'total_rows', 'has_more',
	];

	public function getName(): string
	{
		return 'get_chart_data';
	}

	public function getDescription(): string
	{
		return 'Fetches raw row data for up to ' . self::MAX_CHARTS_PER_CALL . ' charts inside a '
			. 'BI dashboard in a single call. Returns up to ' . self::ROW_LIMIT_DEFAULT . ' rows per chart '
			. 'by default (max ' . self::ROW_LIMIT_MAX . ' via `rowLimit`), with the actual column '
			. 'values (not only stats), column descriptions, and a `has_more` / `total_rows` pair '
			. 'per chart indicating truncation. '
			. 'To page through a chart with more rows than `rowLimit`, repeat the call with '
			. '`offset` advanced by `rowLimit` (0, then rowLimit, then 2·rowLimit, …) while '
			. '`has_more` is true. Paging is only meaningful when rows are deterministically '
			. 'ordered — set `sortBy` and check `slice_stable` first, otherwise pages may overlap '
			. 'or skip rows. '
			. 'Per-chart `stats` here carries TWO slices for every numeric column: '
			. '`stats.<col>.full` — min/max/avg/sum/count/stddev computed over the whole chart, '
			. 'and `stats.<col>.slice` — the same shape recomputed over only the rows returned in '
			. 'this response. Reason about named individual rows using `slice`; reason about whether '
			. 'those rows are unusual versus the chart as a whole using `full`. Do not mix them in '
			. 'one number without saying which slice you cite. '
			. 'Per-chart `slice_stable` is a boolean: when `false`, the chart query has no ORDER BY '
			. 'AND its row_limit was hit, so the rows we sorted in-memory are a non-deterministic '
			. 'cut of the dataset — the top-N you got may not be the real top-N of the chart. '
			. 'When `slice_stable: false`, tell the user the slice may be incomplete. '
			. 'Use this ONLY after get_dashboard_meta, and ONLY when the user asks for concrete '
			. 'row-level detail (e.g. "who closed how many tasks", "top-N rows", "what exactly '
			. 'is in this column"). Do NOT call this tool for generic dashboard summaries — '
			. 'get_dashboard_meta already carries per-chart stats sufficient for that. '
			. 'If the user needs row-level detail from more than ' . self::MAX_CHARTS_PER_CALL
			. ' charts, ask them which ones to expand instead of calling this tool repeatedly. '
			. 'Reuse the exact same `appliedFilters` that were passed to the preceding '
			. 'get_dashboard_meta call. If the user did not specify a period, OMIT `period` '
			. 'from appliedFilters entirely — the server applies the dashboard\'s native '
			. 'default (same range the user sees in the UI). Never hardcode "current month" '
			. 'as a fallback; that silently narrows the range and hides data. '
			. 'Use `sortBy`+`sortOrder` when the user asks for top/bottom-N by a specific column — '
			. 'column names come from `column_descriptions` in the preceding get_dashboard_meta.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'dashboardId' => [
					'type' => 'integer',
					'description' => 'Dashboard ID from the list_dashboards tool result.',
				],
				'chartIds' => [
					'type' => 'array',
					'description' => 'BI dashboard chart ids to fetch. Take them from the `id` '
						. 'field of charts returned by get_dashboard_meta. Up to '
						. self::MAX_CHARTS_PER_CALL . ' ids per call — all of them are fetched in a '
						. 'single request, so prefer batching over multiple calls.',
					'items' => [
						'type' => 'integer',
					],
					'minItems' => 1,
					'maxItems' => self::MAX_CHARTS_PER_CALL,
				],
				'appliedFilters' => [
					'type' => 'array',
					'description' => 'Filters to apply. Reuse the SAME list passed to the preceding '
						. 'get_dashboard_meta call verbatim. '
						. 'For period: name="period", value={"from":"YYYY-MM-DD","to":"YYYY-MM-DD"}. '
						. 'For column filters: column name from available_filters '
						. '(e.g. name="finish_status", value=["Y"]). '
						. 'If the user did not specify a period, OMIT the period entry — the server '
						. 'applies the dashboard\'s native default.',
					'items' => [
						'type' => 'object',
						'properties' => [
							'name' => [
								'type' => 'string',
								'description' => 'Filter name: "period" for date range, '
									. 'or column name for column-level filters.',
							],
							'value' => [
								'description' => 'Filter value. For period: {"from":"YYYY-MM-DD","to":"YYYY-MM-DD"}. '
									. 'For column filters: array of allowed values.',
								'oneOf' => [
									['type' => 'object'],
									['type' => 'array'],
								],
							],
						],
						'required' => ['name', 'value'],
					],
				],
				'urlParams' => [
					'type' => 'object',
					'description' => 'URL parameters for parameterized dashboards '
						. '(e.g. {"tasks_flows_flow_id": 28}). Reuse the same urlParams that '
						. 'were used for get_dashboard_meta on this dashboard.',
					'additionalProperties' => true,
				],
				'rowLimit' => [
					'type' => 'integer',
					'description' => 'Max rows per chart. Default ' . self::ROW_LIMIT_DEFAULT
						. ', hard max ' . self::ROW_LIMIT_MAX . '. Values over max are clamped.',
					'minimum' => 1,
					'maximum' => self::ROW_LIMIT_MAX,
				],
				'offset' => [
					'type' => 'integer',
					'description' => 'Number of rows to skip before returning, for paging through '
						. 'a chart with more rows than `rowLimit`. Default 0. Advance by `rowLimit` '
						. 'on each call while `has_more` is true. Combine with `sortBy` so pages are '
						. 'deterministic; otherwise pages may overlap or skip rows.',
					'minimum' => 0,
				],
				'sortBy' => [
					'type' => 'string',
					'description' => 'Column name to sort rows by before truncation to rowLimit. '
						. 'Use the exact column name from `column_descriptions` in the preceding '
						. 'get_dashboard_meta response. Omit for server default ordering.',
				],
				'sortOrder' => [
					'type' => 'string',
					'description' => 'Sort direction when `sortBy` is set. Default "desc" '
						. '(top-N use case). Ignored when sortBy is not provided.',
					'enum' => ['asc', 'desc'],
				],
			],
			'required' => ['dashboardId', 'chartIds', 'appliedFilters'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		Loader::includeModule('biconnector');

		$dashboardId = (int)$args['dashboardId'];

		$loadResult = $this->loadReadyDashboard($dashboardId, $userId);
		if (!$loadResult->isSuccess())
		{
			throw self::toMcpException($loadResult);
		}
		$dashboard = $loadResult->getData()['dashboard'];
		$externalId = (int)$dashboard->getExternalId();

		$callerFilters = $args['appliedFilters'] ?? [];
		$urlParamOverrides = is_array($args['urlParams'] ?? null) ? $args['urlParams'] : [];

		$rowLimit = self::ROW_LIMIT_DEFAULT;
		if (isset($args['rowLimit']) && is_numeric($args['rowLimit']))
		{
			$rowLimit = max(1, min(self::ROW_LIMIT_MAX, (int)$args['rowLimit']));
		}

		$offset = 0;
		if (isset($args['offset']) && is_numeric($args['offset']))
		{
			$offset = max(0, (int)$args['offset']);
		}

		$sortBy = null;
		$sortOrder = 'desc';
		if (isset($args['sortBy']) && is_string($args['sortBy']) && $args['sortBy'] !== '')
		{
			$sortBy = $args['sortBy'];
			if (isset($args['sortOrder']) && in_array($args['sortOrder'], ['asc', 'desc'], true))
			{
				$sortOrder = $args['sortOrder'];
			}
		}

		$rawChartIds = $args['chartIds'] ?? [];
		if (!is_array($rawChartIds) || empty($rawChartIds))
		{
			throw new McpException('chartIds must be a non-empty array of chart ids.');
		}

		$requestedChartIds = [];
		foreach ($rawChartIds as $id)
		{
			$requestedChartIds[] = (int)$id;
		}
		$requestedChartIds = array_values(array_unique($requestedChartIds));

		if (count($requestedChartIds) > self::MAX_CHARTS_PER_CALL)
		{
			throw new McpException(
				'Too many charts requested: max ' . self::MAX_CHARTS_PER_CALL . ' per call. '
				. 'Reduce `chartIds` to the most relevant charts for the user\'s question. '
				. 'If you must ask the user to narrow down, refer to charts BY THEIR `name` '
				. 'from the previous get_dashboard_meta response — never quote raw chart ids, '
				. 'they are an internal id and confuse the user.',
			);
		}

		$filters = new AppliedFilters($userId);
		if (!empty($callerFilters))
		{
			$dashboardDtoResp = IntegratorFactory::getInstance()->getDashboardById($externalId);
			if ($dashboardDtoResp->hasErrors() || !$dashboardDtoResp->getData())
			{
				throw self::unavailableDashboardException(
					$dashboardDtoResp->getErrors(),
					['stage' => 'get_chart_data.dashboard_metadata', 'dashboard_id' => $dashboardId, 'user_id' => $userId],
				);
			}
			$validateResult = $filters->validate($dashboardDtoResp->getData(), $callerFilters);
			if (!$validateResult->isSuccess())
			{
				throw self::toMcpException($validateResult);
			}
		}
		$resolveResult = $filters->resolve($dashboard, $callerFilters);
		if (!$resolveResult->isSuccess())
		{
			throw self::toMcpException($resolveResult);
		}
		$appliedFilters = $resolveResult->getData()['filters'];
		$supersetFilters = $filters->convertToSupersetExtraFormData($appliedFilters);

		$urlParamsResult = (new UrlParameters($userId))->resolve($dashboardId, $urlParamOverrides);
		if (!$urlParamsResult->isSuccess())
		{
			throw self::toMcpException($urlParamsResult);
		}
		$urlParams = $urlParamsResult->getData()['urlParams'];

		$sort = [];
		if ($sortBy !== null)
		{
			$sort = ['by' => $sortBy, 'order' => $sortOrder];
		}

		$response = IntegratorFactory::getInstance()->getDashboardOverview(
			$externalId,
			$supersetFilters,
			$urlParams,
			chartIds: $requestedChartIds,
			sort: $sort,
			limit: $rowLimit,
			offset: $offset,
		);
		if ($response->hasErrors())
		{
			throw self::unavailableDashboardException(
				$response->getErrors(),
				['stage' => 'get_chart_data.overview', 'dashboard_id' => $dashboardId, 'user_id' => $userId],
			);
		}

		$rawData = $response->getData();
		if ($rawData === null)
		{
			throw self::unavailableDashboardException(
				[new \Bitrix\Main\Error('Empty response from /overview')],
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

		$matchedCharts = [];
		$missing = [];
		if (isset($rawData['missing_chart_ids']) && is_array($rawData['missing_chart_ids']))
		{
			foreach ($rawData['missing_chart_ids'] as $missingId)
			{
				$missing[] = (int)$missingId;
			}
		}

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
				throw self::unavailableDashboardException(
					[new \Bitrix\Main\Error($chart['error'])],
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
			throw new McpException(
				'None of the requested charts were found in dashboard ' . $dashboardId
				. ' (missing: ' . implode(', ', $missing) . ').',
			);
		}

		$filteredData = [
			'dashboard' => [
				'id' => $dashboardId,
				'title' => (string)$dashboard->getTitle(),
			],
			'charts' => $matchedCharts,
			'filter_coverage' => $rawData['filter_coverage'] ?? [],
		];

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

		$transformer = new DashboardDataTransformer($config);
		$result = $transformer->transform($filteredData);

		if (!empty($missing))
		{
			$result['missing_chart_ids'] = $missing;
		}

		return $result;
	}
}

<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\BIConnector\Integration\Superset\Integrator\Integrator;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Filter\AppliedFilters;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Postprocess\DashboardDataTransformer;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Postprocess\TransformerConfig;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\UrlParameter\UrlParameters;
use Bitrix\Main\Loader;

final class GetChartDataTool extends BaseBiTool
{
	private const ROW_LIMIT_DEFAULT = 20;
	private const ROW_LIMIT_MAX = 50;
	private const MAX_CHARTS_PER_CALL = 3;

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

		$loadResult = $this->loadDashboard($dashboardId, $userId);
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
			$dashboardDtoResp = Integrator::getInstance()->getDashboardById($externalId);
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

		$response = Integrator::getInstance()->getDashboardOverview(
			$externalId,
			$supersetFilters,
			$urlParams,
			self::INTEGRATOR_TIMEOUT_SEC,
			$requestedChartIds,
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
		$paginationMeta = [];
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
			$totalRows = 0;
			$hasMore = false;

			$queryResult = $chart['query_result'] ?? null;
			if (is_array($queryResult) && isset($queryResult[0]['data']) && is_array($queryResult[0]['data']))
			{
				$rows = $queryResult[0]['data'];
				$totalRows = count($rows);

				if ($sortBy !== null && $totalRows > 0 && is_array($rows[0]))
				{
					if (!array_key_exists($sortBy, $rows[0]))
					{
						$available = array_keys($rows[0]);
						$chartName = $chart['name'] ?? $chart['slice_name'] ?? '';
						throw new McpException(sprintf(
							'Unknown sortBy column "%s" for chart %d%s. Available columns: %s. '
							. 'Pick the column name verbatim from the chart\'s `stats` keys (numeric '
							. 'metrics) or `column_descriptions` keys returned by the preceding '
							. 'get_dashboard_meta call.',
							$sortBy,
							$chartId,
							$chartName !== '' ? ' ("' . $chartName . '")' : '',
							json_encode(array_values($available), JSON_UNESCAPED_UNICODE),
						));
					}

					if ($totalRows > 1)
					{
						usort($rows, static function ($a, $b) use ($sortBy, $sortOrder) {
							$av = $a[$sortBy] ?? null;
							$bv = $b[$sortBy] ?? null;
							if (is_numeric($av) && is_numeric($bv))
							{
								$cmp = $av <=> $bv;
							}
							else
							{
								$cmp = strcmp((string)$av, (string)$bv);
							}

							return $sortOrder === 'asc' ? $cmp : -$cmp;
						});
					}
				}

				if ($totalRows > $rowLimit)
				{
					$hasMore = true;
					$rows = array_slice($rows, 0, $rowLimit);
				}

				$chart['query_result'][0]['data'] = $rows;
			}

			$matchedCharts[] = $chart;
			$paginationMeta[$chartId] = [
				'total_rows' => $totalRows,
				'has_more' => $hasMore,
			];
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

		$transformer = new DashboardDataTransformer($config);
		$result = $transformer->transform($filteredData);

		foreach ($result['charts'] ?? [] as $idx => $chart)
		{
			$chartId = (int)($chart['id'] ?? 0);
			if (!isset($paginationMeta[$chartId]))
			{
				continue;
			}

			$result['charts'][$idx]['row_limit'] = $rowLimit;
			$result['charts'][$idx]['total_rows'] = $paginationMeta[$chartId]['total_rows'];
			$result['charts'][$idx]['has_more'] = $paginationMeta[$chartId]['has_more'];
		}

		if (!empty($missing))
		{
			$result['missing_chart_ids'] = $missing;
		}

		return $result;
	}
}

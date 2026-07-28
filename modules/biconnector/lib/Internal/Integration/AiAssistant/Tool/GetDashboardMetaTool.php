<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Filter\AppliedFilters;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\UrlParameter\UrlParameters;
use Bitrix\Main\Loader;

final class GetDashboardMetaTool extends BaseBiTool
{
	private const CHART_FIELDS = [
		'id', 'name', 'kind', 'viz_label', 'tab_path', 'description', 'stats', 'stats_omitted', 'column_descriptions',
	];

	private const FILTER_FIELDS = [
		'name', 'kind', 'column', 'default', 'passthrough', 'allowed_values',
	];

	public function getName(): string
	{
		return 'get_dashboard_meta';
	}

	public function getDescription(): string
	{
		return 'Fetches a BI dashboard SKELETON — structure, chart list with semantic tags and '
			. 'numeric aggregates (stats), and the list of available filters. No row-level data. '
			. 'Use this as the FIRST call after list_dashboards to decide what to drill into. '
			. 'Each chart carries: `id`, `name`, `kind` (one of scalar/gauge/timeseries/categorical/'
			. 'ranked_list/geo/other), `tab_path` (breadcrumb like "По исполнителям / Сотрудники"), '
			. 'optional `description`, `stats` with row_count plus per-column min/max/avg/sum/count/stddev '
			. 'for numeric columns, and `column_descriptions` listing every projected column with its type '
			. '(`number`/`string`/`date`/`bool`/`metric`) and an optional `is_percent` flag for metrics '
			. 'stored as ratios (0..1) but displayed as percents. `available_filters` lists dashboard '
			. 'filters with `kind` (date_range / values_list / grain); for `values_list` use '
			. 'get_filter_values to fetch actual values. If the user needs concrete rows (names, '
			. 'top-N items) — pick chart ids from the response and call get_chart_data. '
			. 'Do NOT call this tool for row-level data (use get_chart_data), for filter-value '
			. 'discovery (use get_filter_values), or repeatedly in the same turn — the skeleton '
			. 'is stable for the session, cache it in your reasoning.';
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
				'appliedFilters' => [
					'type' => 'array',
					'description' => 'Filters to apply. Each entry has `name` and `value`. '
						. 'Use name="period" with value={"from":"YYYY-MM-DD","to":"YYYY-MM-DD"} for date range. '
						. 'For column filters use the column name from `available_filters[].column` '
						. '(e.g. name="finish_status", value=["Y"]).',
					'items' => [
						'type' => 'object',
						'properties' => [
							'name' => [
								'type' => 'string',
								'description' => 'Filter name: "period" for date range, '
									. 'or a column name for column filters.',
							],
							'value' => [
								'description' => 'For period: {"from":"YYYY-MM-DD","to":"YYYY-MM-DD"}. '
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
						. '(e.g. {"tasks_flows_flow_id": 28} for a specific Flow). '
						. 'If the dashboard requires a url parameter and it is not provided, '
						. 'the tool errors out with `availableValues` embedded in the error. '
						. 'Global parameters (current_user) are auto-injected server-side — '
						. 'do NOT pass them.',
					'additionalProperties' => true,
				],
			],
			'required' => ['dashboardId'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		Loader::includeModule('biconnector');

		$dashboardId = (int)$args['dashboardId'];
		$callerFilters = $args['appliedFilters'] ?? [];
		$urlParamOverrides = is_array($args['urlParams'] ?? null) ? $args['urlParams'] : [];

		$loadResult = $this->loadReadyDashboard($dashboardId, $userId);
		if (!$loadResult->isSuccess())
		{
			throw self::toMcpException($loadResult);
		}
		$dashboard = $loadResult->getData()['dashboard'];
		$externalId = (int)$dashboard->getExternalId();

		$filters = new AppliedFilters($userId);
		if (!empty($callerFilters))
		{
			$dashboardDtoResp = IntegratorFactory::getInstance()->getDashboardById($externalId);
			if ($dashboardDtoResp->hasErrors() || !$dashboardDtoResp->getData())
			{
				throw self::unavailableDashboardException(
					$dashboardDtoResp->getErrors(),
					['stage' => 'get_dashboard_meta.dashboard_metadata', 'dashboard_id' => $dashboardId, 'user_id' => $userId],
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

		$response = IntegratorFactory::getInstance()->getDashboardOverview(
			$externalId,
			$supersetFilters,
			$urlParams,
		);
		if ($response->hasErrors())
		{
			throw self::unavailableDashboardException(
				$response->getErrors(),
				['stage' => 'get_dashboard_meta.overview', 'dashboard_id' => $dashboardId, 'user_id' => $userId],
			);
		}

		$rawData = $response->getData();
		if ($rawData === null)
		{
			throw self::unavailableDashboardException(
				[new \Bitrix\Main\Error('Empty response from /overview')],
				['stage' => 'get_dashboard_meta.overview_empty', 'dashboard_id' => $dashboardId, 'user_id' => $userId],
			);
		}

		$callerFiltersEcho = [];
		foreach ($appliedFilters as $filter)
		{
			$callerFiltersEcho[] = [
				'name' => $filter['name'] ?? '',
				'value' => $filter['value'] ?? null,
			];
		}

		$meta = [];
		$serverPeriod = $rawData['meta']['period'] ?? null;
		if (is_array($serverPeriod) && !empty($serverPeriod['from']) && !empty($serverPeriod['to']))
		{
			$meta['period'] = [
				'from' => (string)$serverPeriod['from'],
				'to' => (string)$serverPeriod['to'],
			];
		}

		// On big dashboards Superset stats only the first N charts; the rest come
		// back as metadata with `stats_omitted`. Surface the count so the model
		// knows to drill into the rest via get_chart_data.
		if (isset($rawData['meta']['charts_with_stats']))
		{
			$meta['charts_with_stats'] = (int)$rawData['meta']['charts_with_stats'];
			$meta['charts_total'] = (int)($rawData['meta']['charts_total'] ?? 0);
		}

		$result = [
			'dashboard' => [
				'id' => $dashboardId,
				'title' => (string)$dashboard->getTitle(),
			],
			'caller_filters' => $callerFiltersEcho,
			'charts' => self::pickAllowedFields($rawData['charts'] ?? [], self::CHART_FIELDS),
			'available_filters' => self::pickAllowedFields($rawData['available_filters'] ?? [], self::FILTER_FIELDS),
		];
		if (!empty($meta))
		{
			$result['meta'] = $meta;
		}

		if (!empty($rawData['filter_coverage']))
		{
			$result['filter_coverage'] = $rawData['filter_coverage'];
		}

		return $result;
	}

	/**
	 * @param string[] $allowed
	 */
	private static function pickAllowedFields(array $items, array $allowed): array
	{
		$result = [];
		foreach ($items as $item)
		{
			if (!is_array($item))
			{
				continue;
			}

			$picked = [];
			foreach ($allowed as $field)
			{
				if (array_key_exists($field, $item))
				{
					$picked[$field] = $item[$field];
				}
			}
			$result[] = $picked;
		}

		return $result;
	}
}

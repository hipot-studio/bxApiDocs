<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Dashboard;

use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Filter\AppliedFilters;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\UrlParameter\UrlParameters;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Skeleton of a dashboard the actor may view: its charts with their aggregates and its filters,
 * without row-level data. The arguments come resolved: pinned context is the business of an entry point.
 * The shape of `appliedFilters` is not checked here: get_dashboard_meta takes a scalar `value` and wraps it,
 * so an entry without the tool schema checks the shape itself.
 *
 * Data: the answer of get_dashboard_meta — `dashboard`, `caller_filters`, `charts`, `available_filters`,
 * and, when the overview has them, `meta` and `filter_coverage`.
 */
final class GetDashboardOperation
{
	private const CHART_FIELDS = [
		'id', 'name', 'kind', 'viz_label', 'tab_path', 'description', 'stats', 'stats_omitted', 'column_descriptions',
	];

	private const FILTER_FIELDS = [
		'name', 'kind', 'column', 'default', 'passthrough', 'allowed_values',
	];

	private readonly ReadyDashboardGuard $guard;

	public function __construct(?ReadyDashboardGuard $guard = null)
	{
		$this->guard = $guard ?? new ReadyDashboardGuard();
	}

	/**
	 * @param array $appliedFilters Filters as get_dashboard_meta takes them: `name` and `value` each.
	 * @param array $urlParams Values of the url parameters of a parameterized dashboard.
	 */
	public function execute(int $dashboardId, array $appliedFilters, array $urlParams, Actor $actor): Result
	{
		$userId = $actor->userId;

		$loadResult = $this->guard->forView($dashboardId, $actor);
		if (!$loadResult->isSuccess())
		{
			return $loadResult;
		}
		$dashboard = $loadResult->getData()['dashboard'];
		$externalId = (int)$dashboard->getExternalId();

		$filters = new AppliedFilters($userId);
		// The dashboard config is needed even without caller filters: a dashboard with
		// its own period default in the builder must not get the portal period on top of it.
		$dashboardDtoResp = IntegratorFactory::getInstance()->getDashboardById($externalId);
		if ($dashboardDtoResp->hasErrors() || !$dashboardDtoResp->getData())
		{
			return Refusal::integratorFailure(
				$dashboardDtoResp,
				['stage' => 'get_dashboard_meta.dashboard_metadata', 'dashboard_id' => $dashboardId, 'user_id' => $userId],
			);
		}
		$dashboardDto = $dashboardDtoResp->getData();
		if (!empty($appliedFilters))
		{
			$validateResult = $filters->validate($dashboardDto, $appliedFilters);
			if (!$validateResult->isSuccess())
			{
				return Refusal::invalidArgument($validateResult);
			}
		}
		$resolveResult = $filters->resolve($dashboard, $dashboardDto, $appliedFilters);
		if (!$resolveResult->isSuccess())
		{
			return Refusal::invalidArgument($resolveResult);
		}
		$resolvedFilters = $resolveResult->getData()['filters'];
		$supersetFilters = $filters->convertToSupersetExtraFormData($resolvedFilters);

		$urlParamsResult = (new UrlParameters($userId))->resolve($dashboardId, $urlParams);
		if (!$urlParamsResult->isSuccess())
		{
			return Refusal::invalidArgument($urlParamsResult);
		}

		$response = IntegratorFactory::getInstance()->getDashboardOverview(
			$externalId,
			$supersetFilters,
			$urlParamsResult->getData()['urlParams'],
		);
		if ($response->hasErrors())
		{
			return Refusal::integratorFailure(
				$response,
				['stage' => 'get_dashboard_meta.overview', 'dashboard_id' => $dashboardId, 'user_id' => $userId],
			);
		}

		$rawData = $response->getData();
		if ($rawData === null)
		{
			return Refusal::unavailableDashboard(
				[new Error('Empty response from /overview')],
				['stage' => 'get_dashboard_meta.overview_empty', 'dashboard_id' => $dashboardId, 'user_id' => $userId],
			);
		}

		$callerFiltersEcho = [];
		foreach ($resolvedFilters as $filter)
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

		$skeleton = [
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
			$skeleton['meta'] = $meta;
		}

		if (!empty($rawData['filter_coverage']))
		{
			$skeleton['filter_coverage'] = $rawData['filter_coverage'];
		}

		return (new Result())->setData($skeleton);
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

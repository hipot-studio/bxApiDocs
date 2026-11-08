<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Dashboard;

use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Filter\AppliedFilters;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\UrlParameter\UrlParameters;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Distinct values of one filter of a dashboard the actor may view, narrowed by the other filters.
 * The arguments come resolved: pinned context is the business of an entry point. The shape of `appliedFilters`
 * is not checked here: get_filter_values takes a scalar `value` and wraps it, so an entry without the tool schema
 * checks the shape itself.
 *
 * Data: the answer of get_filter_values — `column`, `values_for_display`, `values_for_filter`, `has_more`.
 */
final class GetFilterValuesOperation
{
	public const LIMIT_DEFAULT = 20;
	public const LIMIT_MAX = 50;

	private readonly ReadyDashboardGuard $guard;

	public function __construct(?ReadyDashboardGuard $guard = null)
	{
		$this->guard = $guard ?? new ReadyDashboardGuard();
	}

	/**
	 * @param string|null $search Substring the values are narrowed by; blank means none.
	 * @param int|null $limit Clamped to 1..LIMIT_MAX; null means LIMIT_DEFAULT.
	 * @param array $appliedFilters Filters as get_dashboard_meta takes them: `name` and `value` each.
	 * @param array $urlParams Values of the url parameters of a parameterized dashboard.
	 */
	public function execute(
		int $dashboardId,
		string $filterColumn,
		?string $search,
		?int $limit,
		array $appliedFilters,
		array $urlParams,
		Actor $actor,
	): Result
	{
		$userId = $actor->userId;

		$loadResult = $this->guard->forView($dashboardId, $actor);
		if (!$loadResult->isSuccess())
		{
			return $loadResult;
		}
		$dashboard = $loadResult->getData()['dashboard'];
		$externalId = (int)$dashboard->getExternalId();

		if ($filterColumn === '')
		{
			return Refusal::error(
				FailureCode::InvalidArgument,
				'filterColumn is required: provide the column name to fetch filter values for.',
			);
		}

		$search = $search !== null && trim($search) !== '' ? trim($search) : null;
		$limit = $limit === null ? self::LIMIT_DEFAULT : max(1, min(self::LIMIT_MAX, $limit));

		$filters = new AppliedFilters($userId);
		$dashboardDtoResp = IntegratorFactory::getInstance()->getDashboardById($externalId);
		if ($dashboardDtoResp->hasErrors() || !$dashboardDtoResp->getData())
		{
			return Refusal::integratorFailure(
				$dashboardDtoResp,
				['stage' => 'get_filter_values.dashboard_metadata', 'dashboard_id' => $dashboardId, 'user_id' => $userId],
			);
		}
		$dashboardDto = $dashboardDtoResp->getData();

		$filterColumnCheck = $filters->validate($dashboardDto, [['name' => $filterColumn, 'value' => []]]);
		if (!$filterColumnCheck->isSuccess())
		{
			return Refusal::invalidArgument($filterColumnCheck);
		}

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
		$supersetFilters = $filters->convertToSupersetExtraFormData($resolveResult->getData()['filters']);

		$urlParamsResult = (new UrlParameters($userId))->resolve($dashboardId, $urlParams);
		if (!$urlParamsResult->isSuccess())
		{
			return Refusal::invalidArgument($urlParamsResult);
		}

		$response = IntegratorFactory::getInstance()->getFilterValues(
			$externalId,
			$filterColumn,
			$supersetFilters,
			$urlParamsResult->getData()['urlParams'],
			$search,
			$limit,
		);

		if ($response->hasErrors())
		{
			// The column was checked against the config above; a failure here speaks about Superset.
			return Refusal::integratorFailure(
				$response,
				['stage' => 'get_filter_values.fetch', 'dashboard_id' => $dashboardId, 'user_id' => $userId],
			);
		}

		$rawData = $response->getData();
		if ($rawData === null)
		{
			return Refusal::unavailableDashboard(
				[new Error('Empty response from /filter_values')],
				['stage' => 'get_filter_values.empty', 'dashboard_id' => $dashboardId, 'user_id' => $userId],
			);
		}

		return (new Result())->setData([
			'column' => $rawData['column'] ?? $filterColumn,
			'values_for_display' => $rawData['values_for_display'] ?? [],
			'values_for_filter' => $rawData['values_for_filter'] ?? [],
			'has_more' => (bool)($rawData['has_more'] ?? false),
		]);
	}
}

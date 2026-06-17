<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\BIConnector\Integration\Superset\Integrator\Integrator;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Filter\AppliedFilters;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\UrlParameter\UrlParameters;
use Bitrix\Main\Loader;

final class GetFilterValuesTool extends BaseBiTool
{
	private const LIMIT_DEFAULT = 20;
	private const LIMIT_MAX = 50;

	public function getName(): string
	{
		return 'get_filter_values';
	}

	public function getDescription(): string
	{
		return 'Fetches distinct values available for a single dashboard filter — use this when the '
			. 'user wants to pick one or more options to narrow the data (e.g. "show tasks for Kirill", '
			. '"what departments are in the report"). Returns up to ' . self::LIMIT_DEFAULT . ' values '
			. 'by default (max ' . self::LIMIT_MAX . '), ordered by frequency, with `has_more` flagging '
			. 'truncation. Values come split in two: `values_for_display` (HTML-stripped, safe to '
			. 'show the user) and `values_for_filter` (verbatim DB value — MUST be used as-is when '
			. 'passing back as `appliedFilters[].value`, since test portals sometimes stash HTML in '
			. 'names and exact-match filters only fire on the verbatim string). '
			. 'Call this only for filters whose `kind` is "values_list" in get_dashboard_meta — '
			. 'period (date_range) takes a from/to pair, grain takes an ISO-8601 code from the '
			. '`allowed_values` list, neither needs discovery. '
			. 'Use `search` for partial-match narrowing ("show me users starting with Ан").';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'dashboardId' => [
					'type' => 'integer',
					'description' => 'Dashboard ID from list_dashboards.',
				],
				'filterColumn' => [
					'type' => 'string',
					'description' => 'Target column name — take it from `available_filters[].column` '
						. 'in get_dashboard_meta (e.g. "responsible_name", "dep_name"). '
						. 'NOT the filter display name ("Исполнитель").',
				],
				'search' => [
					'type' => 'string',
					'description' => 'Optional substring. The preview is narrowed by ILIKE %search% '
						. 'case-insensitive before truncation. Helpful when the full list is long '
						. 'and the user gave a fragment of a name.',
					'maxLength' => 200,
				],
				'appliedFilters' => [
					'type' => 'array',
					'description' => 'Same filter list shape as get_dashboard_meta. Scopes the preview '
						. '— a user who already narrowed the dashboard to Q1 sees the Q1 contributors, '
						. 'not the all-time list. Reuse the filters passed to the preceding '
						. 'get_dashboard_meta call.',
					'items' => [
						'type' => 'object',
						'properties' => [
							'name' => ['type' => 'string'],
							'value' => [
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
					'description' => 'Same urlParams as get_dashboard_meta — required for parameterized '
						. 'dashboards, ignored otherwise.',
					'additionalProperties' => true,
				],
				'limit' => [
					'type' => 'integer',
					'description' => 'Max values to return. Default ' . self::LIMIT_DEFAULT
						. ', hard max ' . self::LIMIT_MAX . '. Values over max are clamped.',
					'minimum' => 1,
					'maximum' => self::LIMIT_MAX,
				],
			],
			'required' => ['dashboardId', 'filterColumn'],
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

		$filterColumn = (string)($args['filterColumn'] ?? '');
		if ($filterColumn === '')
		{
			throw new McpException('filterColumn is required.');
		}

		$search = null;
		if (isset($args['search']) && is_string($args['search']) && trim($args['search']) !== '')
		{
			$search = trim($args['search']);
		}

		$limit = self::LIMIT_DEFAULT;
		if (isset($args['limit']) && is_numeric($args['limit']))
		{
			$limit = max(1, min(self::LIMIT_MAX, (int)$args['limit']));
		}

		$callerFilters = $args['appliedFilters'] ?? [];
		$urlParamOverrides = is_array($args['urlParams'] ?? null) ? $args['urlParams'] : [];

		$filters = new AppliedFilters($userId);
		if (!empty($callerFilters))
		{
			$dashboardDtoResp = Integrator::getInstance()->getDashboardById($externalId);
			if ($dashboardDtoResp->hasErrors() || !$dashboardDtoResp->getData())
			{
				throw self::unavailableDashboardException(
					$dashboardDtoResp->getErrors(),
					['stage' => 'get_filter_values.dashboard_metadata', 'dashboard_id' => $dashboardId, 'user_id' => $userId],
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

		$response = Integrator::getInstance()->getFilterValues(
			$externalId,
			$filterColumn,
			$supersetFilters,
			$urlParams,
			$search,
			$limit,
			self::INTEGRATOR_TIMEOUT_SEC,
		);

		if ($response->hasErrors())
		{
			throw self::unavailableDashboardException(
				$response->getErrors(),
				['stage' => 'get_filter_values.fetch', 'dashboard_id' => $dashboardId, 'user_id' => $userId],
			);
		}

		$rawData = $response->getData();
		if ($rawData === null)
		{
			throw self::unavailableDashboardException(
				[new \Bitrix\Main\Error('Empty response from /filter_values')],
				['stage' => 'get_filter_values.empty', 'dashboard_id' => $dashboardId, 'user_id' => $userId],
			);
		}

		return [
			'column' => $rawData['column'] ?? $filterColumn,
			'values_for_display' => $rawData['values_for_display'] ?? [],
			'values_for_filter' => $rawData['values_for_filter'] ?? [],
			'has_more' => (bool)($rawData['has_more'] ?? false),
		];
	}
}

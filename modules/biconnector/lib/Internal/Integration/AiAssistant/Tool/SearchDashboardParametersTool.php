<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\BIConnector\Internal\Integration\AiAssistant\UrlParameter\UrlParameters;
use Bitrix\Main\Loader;

final class SearchDashboardParametersTool extends BaseBiTool
{
	public function getName(): string
	{
		return 'search_dashboard_parameters';
	}

	public function getDescription(): string
	{
		return 'Official channel to discover what scope values can be supplied for a dashboard. '
			. 'Returns the list of accessible parameter values (task flows, workflow templates, ...) '
			. 'the current user can pick from for each required parameter — `paramCode`, a '
			. 'human-readable `paramTitle`, and `availableValues` (id + name). '
			. 'Call this BEFORE get_dashboard_meta for any dashboard that may be parameterized, '
			. 'or if `missing_url_parameter` was already raised: show the values to the user, ask '
			. 'which one, then call get_dashboard_meta with urlParams {"<paramCode>": <id>}. '
			. 'If `required` is false, the dashboard is not parameterized and you can call '
			. 'get_dashboard_meta directly. Lightweight metadata check — runs no report queries. '
			. 'Does NOT cover regular column filters (period, department, etc.) — those come from '
			. 'get_dashboard_meta.';
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
				'search' => [
					'type' => 'string',
					'maxLength' => 200,
					'description' => 'Optional case-insensitive substring to narrow each parameter\'s '
						. 'availableValues by name. Use when the user named the entity (e.g. a specific '
						. 'flow or template) or the full list would be too long. Omit to get the full list.',
				],
				'offset' => [
					'type' => 'integer',
					'description' => 'Optional zero-based offset to page through a long list. When a '
						. 'parameter\'s `hasMore` is true, call again with offset increased by the page '
						. 'size to fetch the next page. Prefer `search` over paging when the user named '
						. 'the entity. Defaults to 0.',
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
		$search = isset($args['search']) ? (string)$args['search'] : null;
		$offset = isset($args['offset']) ? max(0, (int)$args['offset']) : 0;

		$loadResult = $this->loadViewableDashboard($dashboardId, $userId);
		if (!$loadResult->isSuccess())
		{
			throw self::toMcpException($loadResult);
		}

		$describeResult = (new UrlParameters($userId))->describe($dashboardId, $search, $offset);
		if (!$describeResult->isSuccess())
		{
			throw self::toMcpException($describeResult);
		}

		$parameters = $describeResult->getData()['parameters'] ?? [];

		return [
			'dashboardId' => $dashboardId,
			'required' => !empty($parameters),
			'parameters' => $parameters,
		];
	}
}

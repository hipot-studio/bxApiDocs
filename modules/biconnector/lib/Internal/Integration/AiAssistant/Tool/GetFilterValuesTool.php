<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Request\Dto\RequestSubjectDto;
use Bitrix\BIConnector\Integration\Superset\Integrator\ActAsFailure;
use Bitrix\BIConnector\Integration\Superset\Integrator\Dto;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Internal\Feature\FlagGate;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Filter\AppliedFilters;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Operation\OperationImpact;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\UrlParameter\UrlParameters;
use Bitrix\BIConnector\Internal\Operation\Builder\Argument\BuilderArgumentContract;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\Channel;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\GetFilterValuesOperation;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

final class GetFilterValuesTool extends BaseSubjectAwareBiTool
{
	private const LIMIT_DEFAULT = 20;
	private const LIMIT_MAX = 50;

	public function getName(): string
	{
		return 'get_filter_values';
	}

	public function getImpact(): OperationImpact
	{
		return OperationImpact::Read;
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
		return BuilderArgumentContract::schema(
			BuilderArgumentContract::methodForTool($this->getName()),
			'mcp',
			[
				'dashboardId' => 'Dashboard ID from list_dashboards.',
				'filterColumn' => 'Target column from `available_filters[].column` in get_dashboard_meta, '
					. 'not the filter display name.',
				'search' => 'Optional case-insensitive substring to narrow the values before truncation.',
				'appliedFilters' => 'Same filter list shape as get_dashboard_meta. Reuse filters from the preceding call.',
				'appliedFilters[].name' => 'Filter name: "period" or a column name.',
				'appliedFilters[].value' => 'For period: from/to date object. For column filters: array of values.',
				'urlParams' => 'Same urlParams as get_dashboard_meta; required for parameterized dashboards.',
				'limit' => 'Max values to return. Default ' . self::LIMIT_DEFAULT
					. ', maximum ' . self::LIMIT_MAX . '.',
			],
		);
	}

	protected function executeStructured(int $userId, ?RequestSubjectDto $subject, array $args): array
	{
		if (!(new FlagGate())->isEnabled(FlagGate::BUILDER_MCP_OPERATIONS, $userId))
		{
			return $this->legacyExecuteStructured($userId, $subject, $args);
		}

		self::requireBiconnectorModule();

		$search = isset($args['search']) && is_string($args['search']) ? $args['search'] : null;
		$limit = isset($args['limit']) && is_numeric($args['limit']) ? (int)$args['limit'] : null;

		// Resolved per call: aiassistant keeps a tool as a process-wide singleton.
		$result = ServiceLocator::getInstance()->get(GetFilterValuesOperation::class)->execute(
			(int)$args['dashboardId'],
			(string)($args['filterColumn'] ?? ''),
			$search,
			$limit,
			$args['appliedFilters'] ?? [],
			is_array($args['urlParams'] ?? null) ? $args['urlParams'] : [],
			new Actor($userId, Channel::Mcp),
		);
		if (!$result->isSuccess())
		{
			throw self::toMcpException(OperationRefusal::forTool($result));
		}

		return $result->getData();
	}

	private function legacyExecuteStructured(int $userId, ?RequestSubjectDto $subject, array $args): array
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

		$filterColumn = (string)($args['filterColumn'] ?? '');
		if ($filterColumn === '')
		{
			throw new McpException('filterColumn is required: provide the column name to fetch filter values for.');
		}

		$pinnedArguments = new PinnedArgumentResolver();
		$runId = $pinnedArguments->beginRunCall($subject, $userId, $this->getName(), ['filterColumn' => $filterColumn]);
		$budget = DigestCallBudget::start($runId);

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
		$dashboardDtoResp = IntegratorFactory::getInstance()->getDashboardById(
			$externalId,
			$budget->getStreamTimeout(0),
			arbitrateServiceFailure: $runId === null,
		);
		if ($dashboardDtoResp->hasErrors() || !$dashboardDtoResp->getData())
		{
			$context = ['stage' => 'get_filter_values.dashboard_metadata', 'dashboard_id' => $dashboardId, 'user_id' => $userId];

			throw $pinnedArguments->findRunFailure($runId, $userId, $dashboardDtoResp, $context)
				?? self::integratorFailureException($dashboardDtoResp, $context);
		}
		$dashboardDto = $dashboardDtoResp->getData();

		$filterColumnCheck = $filters->validate($dashboardDto, [['name' => $filterColumn, 'value' => []]]);
		if (!$filterColumnCheck->isSuccess())
		{
			throw self::toMcpException($filterColumnCheck);
		}

		if (!empty($callerFilters))
		{
			$validateResult = $filters->validate($dashboardDto, $callerFilters);
			if (!$validateResult->isSuccess())
			{
				throw self::toMcpException($validateResult);
			}
		}
		$resolveResult = $filters->resolve($dashboard, $dashboardDto, $callerFilters);
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

		$exhausted = $pinnedArguments->findExhaustedBudget(
			$runId,
			$userId,
			$budget,
			['stage' => 'get_filter_values.fetch', 'dashboard_id' => $dashboardId, 'user_id' => $userId],
		);
		if ($exhausted !== null)
		{
			throw $exhausted;
		}

		$response = IntegratorFactory::getInstance()->getFilterValues(
			self::findActAsUser($userId),
			$externalId,
			$filterColumn,
			$supersetFilters,
			$urlParams,
			$search,
			$limit,
			$budget->getStreamTimeout(60),
			arbitrateServiceFailure: $runId === null,
		);

		if ($response->hasErrors())
		{
			$columnCheck = $this->checkFilterColumn($externalId, $filterColumn, $filters, $dashboardDto);
			if (!$columnCheck->isSuccess())
			{
				throw self::toMcpException($columnCheck);
			}

			$context = ['stage' => 'get_filter_values.fetch', 'dashboard_id' => $dashboardId, 'user_id' => $userId];

			throw $pinnedArguments->findRunFailure($runId, $userId, $response, $context)
				?? self::dashboardReadFailureException($response, $context);
		}

		$rawData = $response->getData();
		if ($rawData === null)
		{
			throw self::unavailableDashboardException(
				[new \Bitrix\Main\Error('Empty response from /filter_values')],
				['stage' => 'get_filter_values.empty', 'dashboard_id' => $dashboardId, 'user_id' => $userId],
			);
		}
		if (ActAsFailure::isFilterAccessDenied($rawData))
		{
			throw new McpException(ActAsFailure::FILTER_ACCESS_DENIED_MESSAGE);
		}

		$pinnedArguments->completeRunCall($runId, $userId);

		return [
			'column' => $rawData['column'] ?? $filterColumn,
			'values_for_display' => $rawData['values_for_display'] ?? [],
			'values_for_filter' => $rawData['values_for_filter'] ?? [],
			'has_more' => (bool)($rawData['has_more'] ?? false),
		];
	}

	/**
	 * Validates $filterColumn against the dashboard's known filter columns (reusing
	 * the AppliedFilters fuzzy-suggest check). A failed Result means the column is
	 * unknown; a successful one means it is valid and the caller should surface the
	 * original Superset error.
	 */
	private function checkFilterColumn(
		int $externalId,
		string $filterColumn,
		AppliedFilters $filters,
		?Dto\Dashboard $dashboardDto,
	): Result
	{
		if ($dashboardDto === null)
		{
			$resp = IntegratorFactory::getInstance()->getDashboardById($externalId);
			if ($resp->hasErrors() || !$resp->getData())
			{
				return new Result();
			}
			$dashboardDto = $resp->getData();
		}

		return $filters->validate($dashboardDto, [['name' => $filterColumn, 'value' => []]]);
	}
}

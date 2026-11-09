<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Request\Dto\RequestSubjectDto;
use Bitrix\BIConnector\Internal\Feature\FlagGate;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Operation\OperationImpact;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\UrlParameter\UrlParameters;
use Bitrix\BIConnector\Internal\Operation\Builder\Argument\BuilderArgumentContract;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\Channel;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\SearchParametersOperation;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

final class SearchDashboardParametersTool extends BaseSubjectAwareBiTool
{
	public function getName(): string
	{
		return 'search_dashboard_parameters';
	}

	public function getImpact(): OperationImpact
	{
		return OperationImpact::Read;
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
		return BuilderArgumentContract::schema(
			BuilderArgumentContract::methodForTool($this->getName()),
			'mcp',
			['dashboardId' => 'Dashboard ID from the list_dashboards tool result.',
				'search' => 'Optional case-insensitive substring to narrow each parameter\'s availableValues by name. '
					. 'Use when the user named the entity; omit to get the full list.',
				'offset' => 'Optional offset to page through a long list. Negative values normalize to 0. '
					. 'When a parameter hasMore is true, increase by the page size. Prefer search for a named entity.',
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

		// Resolved per call: aiassistant keeps a tool as a process-wide singleton.
		$result = ServiceLocator::getInstance()->get(SearchParametersOperation::class)->execute(
			(int)$args['dashboardId'],
			isset($args['search']) ? (string)$args['search'] : null,
			isset($args['offset']) ? max(0, (int)$args['offset']) : 0,
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

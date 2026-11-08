<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Internal\Feature\FlagGate;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Operation\OperationImpact;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\Channel;
use Bitrix\BIConnector\Internal\Operation\Builder\Chart\GetChartOperation;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;

final class GetChartTool extends BaseBiTool
{
	/**
	 * Keys kept from the raw Superset `params` blob when returning a chart to the agent.
	 *
	 * Mirrors the set of keys that ChartParamsBuilder writes for the supported
	 * vizTypes (bar/line/pie/table/big_number) plus a few legacy/UI keys that
	 * may appear in charts created via the Superset UI (`granularity_sqla`,
	 * `time_range`, `temporal_columns_lookup`, `columns`).
	 *
	 * Decorative/styling keys (color_scheme, legendType, outerRadius,
	 * header_font_size, *_format, show_legend, etc.) are intentionally
	 * dropped — the agent does not read or write them through the DSL and they
	 * just waste context. ReplaceChartConfigTool accepts only the same fields
	 * we whitelist here.
	 */
	private const PARAMS_WHITELIST = [
		// Identity
		'viz_type',
		'datasource',
		// Aggregations and projection
		'metrics',
		'metric',
		'groupby',
		'columns',
		'all_columns',
		'query_mode',
		// Axes / temporal binding
		'x_axis',
		'granularity_sqla',
		'temporal_columns_lookup',
		// Filtering and time range
		'adhoc_filters',
		'time_range',
		// Limits / ordering
		'row_limit',
		'order_desc',
		'orderby',
	];

	public function getName(): string
	{
		return 'get_chart';
	}

	public function getImpact(): OperationImpact
	{
		return OperationImpact::Read;
	}

	public function getDescription(): string
	{
		return 'Returns the configuration of a single Superset chart by id: '
			. 'name, description, visualization type, source dataset, ownership, and '
			. 'a trimmed `params` blob restricted to the fields that drive the query '
			. '(metrics, dimensions, columns/all_columns, x_axis, adhoc_filters, '
			. 'row_limit, query_mode, temporal_columns_lookup, granularity_sqla, '
			. 'time_range). Decorative/styling keys (color scheme, legend, label '
			. 'formatting, fonts) are stripped to save context — `replace_chart_config` '
			. 'does not accept them either, so they are not actionable. '
			. 'Use this tool to inspect an existing chart before editing it (always '
			. 'call get_chart first if you plan to use `replace_chart_config`, since '
			. 'that tool fully replaces the config), or to understand how a chart is '
			. 'wired to its dataset. '
			. 'Call list_charts first to discover available chart ids.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'chartId' => [
					'type' => 'integer',
					'description' => 'Chart id from list_charts.',
					'minimum' => 1,
				],
			],
			'required' => ['chartId'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		if (!(new FlagGate())->isEnabled(FlagGate::BUILDER_MCP_OPERATIONS, $userId))
		{
			return $this->legacyExecuteStructured($userId, ...$args);
		}

		self::requireBiconnectorModule();

		// Resolved per call: aiassistant keeps a tool as a process-wide singleton.
		$result = ServiceLocator::getInstance()->get(GetChartOperation::class)->execute(
			(int)($args['chartId'] ?? 0),
			new Actor($userId, Channel::Mcp),
		);
		if (!$result->isSuccess())
		{
			throw self::toMcpException(OperationRefusal::forTool($result));
		}

		return $result->getData();
	}

	private function legacyExecuteStructured(int $userId, ...$args): array
	{
		Loader::includeModule('biconnector');

		if (!$this->getAccessController($userId)->check(ActionDictionary::ACTION_BIC_ACCESS))
		{
			throw new McpException('Access denied: ACTION_BIC_ACCESS is required.');
		}

		$chartId = (int)($args['chartId'] ?? 0);
		if ($chartId <= 0)
		{
			throw new McpException('chartId is required and must be a positive integer.');
		}

		$response = IntegratorFactory::getInstance()->getChartById($chartId);
		if ($response->hasErrors())
		{
			throw self::unavailableBiException(
				$response->getErrors(),
				['stage' => 'get_chart', 'user_id' => $userId, 'chart_id' => $chartId],
			);
		}

		$data = $response->getData();
		if (!is_array($data))
		{
			$data = [];
		}

		$params = null;
		$rawParams = $data['params'] ?? null;
		if (is_string($rawParams) && $rawParams !== '')
		{
			try
			{
				$decoded = Json::decode($rawParams);
				$params = is_array($decoded)
					? array_intersect_key($decoded, array_flip(self::PARAMS_WHITELIST))
					: $decoded;
			}
			catch (\Throwable)
			{
				$params = $rawParams;
			}
		}
		elseif (is_array($rawParams))
		{
			$params = array_intersect_key($rawParams, array_flip(self::PARAMS_WHITELIST));
		}

		return [
			'id' => (int)($data['id'] ?? $chartId),
			'name' => (string)($data['slice_name'] ?? $data['chart_name'] ?? ''),
			'description' => (string)($data['description'] ?? ''),
			'viz_type' => (string)($data['viz_type'] ?? ''),
			'dataset_id' => (int)($data['datasource_id'] ?? $data['dataset_id'] ?? 0),
			'datasource_type' => (string)($data['datasource_type'] ?? 'table'),
			'params' => $params,
			'owners' => $data['owners'] ?? [],
			'dashboards' => self::trimDashboards($data['dashboards'] ?? []),
		];
	}

	/**
	 * Reduce the chart's `dashboards` list to the fields an agent needs (id +
	 * title). The raw Superset payload carries each dashboard's full
	 * `json_metadata` blob, which is large and irrelevant when inspecting a
	 * chart — keeping it would waste the model's context.
	 *
	 * @param mixed $dashboards
	 * @return array<int, array{id: int, title: string}>
	 */
	private static function trimDashboards(mixed $dashboards): array
	{
		if (!is_array($dashboards))
		{
			return [];
		}

		$result = [];
		foreach ($dashboards as $dashboard)
		{
			if (!is_array($dashboard))
			{
				continue;
			}

			$result[] = [
				'id' => (int)($dashboard['id'] ?? 0),
				'title' => (string)($dashboard['dashboard_title'] ?? $dashboard['title'] ?? ''),
			];
		}

		return $result;
	}
}

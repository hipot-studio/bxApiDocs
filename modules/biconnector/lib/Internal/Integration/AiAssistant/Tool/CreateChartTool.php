<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Internal\Feature\FlagGate;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Chart\ChartParamsBuilder;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Operation\OperationImpact;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\Channel;
use Bitrix\BIConnector\Internal\Operation\Builder\Chart\CreateChartOperation;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

final class CreateChartTool extends BaseBiTool
{
	public function getName(): string
	{
		return 'create_chart';
	}

	public function getImpact(): OperationImpact
	{
		return OperationImpact::Modify;
	}

	public function getDescription(): string
	{
		return 'Creates a new Superset chart on top of an existing dataset using a '
			. 'high-level config. Supported visualization types: '
			. implode(', ', ChartParamsBuilder::VIZ_TYPES) . '. '
			. 'REQUIRED WORKFLOW — do not skip steps, inventing column names will '
			. 'produce a broken chart: '
			. '1) Call `list_datasets` to pick the source dataset and read its '
			. '`table_name`, `schema`, and `sql`. '
			. '2) MANDATORY: obtain the real column names of that dataset before '
			. 'filling `metrics`/`dimensions`/`xAxis`/`filters`/`temporalColumn`. '
			. 'For physical datasets (empty `sql`) call '
			. '`get_bi_table_schema(tableName, schema)` with the values from step 1 '
			. 'and copy column names VERBATIM (they are case-sensitive). '
			. 'For virtual datasets (non-empty `sql`) read the SELECT clause of '
			. 'that `sql` to extract the exposed column aliases. '
			. '3) Pick the dataset\'s date/datetime column for `temporalColumn` — '
			. 'this is what binds the chart to the dashboard date filter. Without '
			. 'it Superset cannot rewrite the chart\'s time filter and the '
			. 'dashboard date filter is silently dropped on this chart. '
			. '4) Optionally call `get_chart` on a similar existing chart over the '
			. 'same dataset to see how columns are wired into `params`. '
			. '5) Call this tool, passing column names exactly as obtained in '
			. 'step 2 — never guess or normalize them. '
			. 'The agent provides metrics (column + aggregate), dimensions (group-by '
			. 'columns), and optional filters; the server assembles the underlying '
			. 'Superset `params` blob and injects a TEMPORAL_RANGE adhoc filter on '
			. '`temporalColumn` so the dashboard date filter propagates into SQL. '
			. 'For bar/line/scatter charts, pass the time/category column as `xAxis`. '
			. 'For table charts: provide only `dimensions` for raw-rows mode, or '
			. '`metrics` + `dimensions` for aggregate mode. '
			. 'Returns the new chart `id`, `name`, `viz_type`, `dataset_id`.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'name' => [
					'type' => 'string',
					'minLength' => 1,
					'description' => 'Chart display name. Must be non-empty.',
				],
				'datasetId' => [
					'type' => 'integer',
					'description' => 'Source dataset id (from list_datasets).',
					'minimum' => 1,
				],
				'vizType' => [
					'type' => 'string',
					'description' => 'Visualization type alias.',
					'enum' => ChartParamsBuilder::VIZ_TYPES,
				],
				'temporalColumn' => [
					'type' => 'string',
					'description' => 'Date/datetime column from the dataset that the '
						. 'chart binds to. MANDATORY — every chart must react to the '
						. 'dashboard date filter, and Superset only rewrites the '
						. 'comparator of filters with operator=TEMPORAL_RANGE. The '
						. 'server injects exactly such a filter on this column. Pass '
						. 'the real column name from get_bi_table_schema (e.g. '
						. '`CREATED_DATE`, `CLOSED_DATE`); never guess. If the chart '
						. 'is over a virtual dataset (non-empty `sql`), pick the '
						. 'date alias exposed by its SELECT clause.',
				],
				'metrics' => [
					'type' => 'array',
					'description' => 'Metric definitions (column + aggregate). '
						. 'Required for bar/line/scatter/pie/big_number. Optional for '
						. 'table (omit for raw-row mode). pie/big_number use only the '
						. 'first metric; bar/line/scatter/table accept multiple.',
					'items' => [
						'type' => 'object',
						'properties' => [
							'column' => [
								'type' => 'string',
								'description' => 'Column name from the dataset.',
							],
							'aggregate' => [
								'type' => 'string',
								'description' => 'Aggregation function applied to the column.',
								'enum' => ['SUM', 'AVG', 'COUNT', 'COUNT_DISTINCT', 'MIN', 'MAX'],
							],
							'label' => [
								'type' => 'string',
								'description' => 'Optional display label. Defaults to '
									. '"AGGREGATE(column)".',
							],
						],
						'required' => ['column', 'aggregate'],
						'additionalProperties' => false,
					],
				],
				'dimensions' => [
					'type' => 'array',
					'description' => 'Dimension column names (group-by). '
						. 'For bar/line/scatter: dimensions other than xAxis become '
						. 'series breakdown. For pie: slice categories. For table '
						. 'aggregate mode: group-by columns. For table raw mode: '
						. 'projected columns. Not used by big_number.',
					'items' => ['type' => 'string'],
				],
				'xAxis' => [
					'type' => 'string',
					'description' => 'X-axis column for bar/line/scatter charts. MUST be a '
						. 'date/datetime column — bar/line/scatter internally map to '
						. 'echarts_timeseries_* viz_types and require a temporal '
						. 'axis. A categorical x-axis will break the chart and '
						. 'surface a warning in the UI. Usually the same as '
						. '`temporalColumn`. If omitted, the first dimension is used.',
				],
				'filters' => [
					'type' => 'array',
					'description' => 'Filter expressions applied to the dataset. '
						. 'IMPORTANT — date/datetime columns: use ONLY operator '
						. '`between` for filtering by time. Operators `<`/`>`/`=`/`!=` '
						. 'compare as strings and break on Trino/Presto datasources '
						. '(type mismatch: timestamp vs varchar). For text/number '
						. 'columns the full operator list is fine.',
					'items' => [
						'type' => 'object',
						'properties' => [
							'column' => [
								'type' => 'string',
								'description' => 'Column to filter on.',
							],
							'operator' => [
								'type' => 'string',
								'description' => 'Comparison operator. Use `between` '
									. 'for date/datetime columns — value is either a '
									. 'Superset time expression string ("Last 6 months", '
									. '"2024-01-01 : 2024-03-31", "No filter") or a '
									. '`[from, to]` array of two ISO strings.',
								'enum' => [
									'=', '!=', '<', '>', '<=', '>=',
									'in', 'not_in', 'like', 'is_null', 'is_not_null',
									'between',
								],
							],
							'value' => [
								'description' => 'Comparison value. Use array for '
									. '`in`/`not_in`. Omit for `is_null`/`is_not_null`. '
									. 'For `between`: a Superset time expression string '
									. 'or a [from, to] pair.',
								'oneOf' => [
									['type' => 'string'],
									['type' => 'number'],
									['type' => 'boolean'],
									['type' => 'array'],
									['type' => 'null'],
								],
							],
						],
						'required' => ['column', 'operator'],
						'additionalProperties' => false,
					],
				],
				'description' => [
					'type' => 'string',
					'description' => 'Optional chart description shown in the UI.',
				],
				'rowLimit' => [
					'type' => 'integer',
					'description' => 'Row limit for the underlying query. Defaults '
						. 'depend on vizType (pie=100, table=1000, otherwise 10000). '
						. 'Hard maximum 50000.',
					'minimum' => 1,
					'maximum' => 50000,
				],
			],
			'required' => ['name', 'datasetId', 'vizType', 'temporalColumn'],
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
		$result = ServiceLocator::getInstance()->get(CreateChartOperation::class)->execute(
			$args,
			new Actor($userId, Channel::Mcp),
		);
		if (!$result->isSuccess())
		{
			throw self::toMcpException($result);
		}

		return $result->getData();
	}

	private function legacyExecuteStructured(int $userId, ...$args): array
	{
		Loader::includeModule('biconnector');

		if (!Feature::isBuilderEnabled())
		{
			throw new McpException('BI Builder feature is not enabled on this portal.');
		}

		$accessController = $this->getAccessController($userId);
		if (!$accessController->check(ActionDictionary::ACTION_BIC_ACCESS))
		{
			throw new McpException('Access denied: ACTION_BIC_ACCESS is required.');
		}
		if (!$accessController->check(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT))
		{
			throw new McpException('Access denied: ACTION_BIC_DASHBOARD_EDIT is required to create charts.');
		}

		$name = trim((string)($args['name'] ?? ''));
		if ($name === '')
		{
			throw new McpException('name is required and must be a non-empty string.');
		}

		$payload = ChartParamsBuilder::build($args);

		$response = IntegratorFactory::getInstance()->createChart($payload);
		if ($response->hasErrors())
		{
			throw self::unavailableBiException(
				$response->getErrors(),
				[
					'stage' => 'create_chart',
					'user_id' => $userId,
					'dataset_id' => (int)($args['datasetId'] ?? 0),
					'viz_type' => (string)($args['vizType'] ?? ''),
					'name' => (string)($args['name'] ?? ''),
				],
			);
		}

		$data = $response->getData();
		if (!is_array($data))
		{
			$data = [];
		}

		return [
			'id' => (int)($data['id'] ?? 0),
			'name' => (string)($data['chart_name'] ?? $data['slice_name'] ?? $args['name'] ?? ''),
			'viz_type' => (string)($data['viz_type'] ?? $payload['viz_type'] ?? ''),
			'dataset_id' => (int)($data['dataset_id'] ?? $data['datasource_id'] ?? $args['datasetId'] ?? 0),
		];
	}
}

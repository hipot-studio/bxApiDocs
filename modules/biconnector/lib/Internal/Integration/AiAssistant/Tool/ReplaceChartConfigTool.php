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
use Bitrix\BIConnector\Internal\Operation\Builder\Argument\BuilderArgumentContract;
use Bitrix\BIConnector\Internal\Operation\Builder\Channel;
use Bitrix\BIConnector\Internal\Operation\Builder\Chart\ReplaceChartConfigOperation;
use Bitrix\BIConnector\Internal\Operation\Builder\Chart\ChartDatasetGuard;
use Bitrix\BIConnector\Internal\Operation\Builder\EntityLookup;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

final class ReplaceChartConfigTool extends BaseBiTool
{
	public function getName(): string
	{
		return 'replace_chart_config';
	}

	public function getImpact(): OperationImpact
	{
		return OperationImpact::Modify;
	}

	public function getDescription(): string
	{
		return 'Performs a FULL replace of an existing chart\'s configuration: '
			. 'visualization type, source dataset, metrics, dimensions, xAxis, '
			. 'filters, rowLimit, and the temporal column binding. '
			. 'Supported visualization types: ' . implode(', ', ChartParamsBuilder::VIZ_TYPES) . '. '
			. 'IMPORTANT — this is a TOTAL REPLACE, not a patch. The new `params` blob '
			. 'is rebuilt from the fields you pass exactly the same way `create_chart` '
			. 'does. Any field you OMIT reverts to ChartParamsBuilder defaults for the '
			. 'chosen vizType, NOT to the chart\'s previous values. To preserve a '
			. 'subset of the existing config, ALWAYS call `get_chart` first, read the '
			. 'current params, and re-pass every field you want to keep. '
			. 'Does NOT modify chart name or description — use `update_chart_metadata` for that. '
			. 'REQUIRED WORKFLOW for picking column names — never invent them: '
			. '1) Call `get_chart` on the target chart to see the current dataset and '
			. 'column wiring. '
			. '2) If switching to a new dataset, call `list_datasets` and, for physical '
			. 'datasets, `get_bi_table_schema` to read the real column names '
			. '(case-sensitive — copy verbatim). For virtual datasets read the SELECT '
			. 'clause of their `sql`. '
			. '3) Pick a date/datetime column for `temporalColumn` — this binds the '
			. 'chart to the dashboard date filter. Without it the dashboard date filter '
			. 'is silently dropped on this chart. '
			. 'TWO-STEP CONFIRMATION (MANDATORY): the first call (omit `confirm`, or pass '
			. '`confirm=false`) returns a preview of what will be overwritten (chart id, name '
			. 'and the new vizType/datasetId) and changes nothing. Relay it to the user, get '
			. 'EXPLICIT approval, then call again with `confirm=true`. Never set `confirm=true` '
			. 'on your own, never chain the two calls, never infer approval from earlier '
			. 'conversation. '
			. 'Returns the preview on the first call, and the updated chart id, name, '
			. 'viz_type on the confirmed call.';
	}

	public function getInputSchema(): array
	{
		$schema = BuilderArgumentContract::schema(
			'biconnector.builder.chart.config.set',
			'mcp',
			[
				'chartId' => 'Chart id to update (from list_charts). Positive integer.',
				'datasetId' => 'Source dataset id (from list_datasets). May differ from '
					. 'the chart\'s current dataset — this is a full replace.',
				'vizType' => 'Visualization type alias for the new config.',
				'temporalColumn' => 'Date/datetime column from the dataset that the chart '
					. 'binds to. MANDATORY — every chart must react to the dashboard '
					. 'date filter, and Superset only rewrites filters with '
					. 'operator=TEMPORAL_RANGE. The server injects exactly such a '
					. 'filter on this column. Pass the real column name from '
					. 'get_bi_table_schema (e.g. `CREATED_DATE`); never guess.',
				'metrics' => 'Metric definitions (column + aggregate). '
					. 'Required for bar/line/scatter/pie/big_number. Optional for '
					. 'table (omit for raw-row mode). pie/big_number use only the '
					. 'first metric; bar/line/scatter/table accept multiple.',
				'metrics[].column' => 'Column name from the dataset.',
				'metrics[].aggregate' => 'Aggregation function applied to the column.',
				'metrics[].label' => 'Optional display label. Defaults to '
					. '"AGGREGATE(column)".',
				'dimensions' => 'Dimension column names (group-by). '
					. 'For bar/line/scatter: dimensions other than xAxis become '
					. 'series breakdown. For pie: slice categories. For table '
					. 'aggregate mode: group-by columns. For table raw mode: '
					. 'projected columns. Not used by big_number.',
				'xAxis' => 'X-axis column for bar/line/scatter charts. MUST be a '
					. 'date/datetime column — bar/line/scatter internally map to '
					. 'echarts_timeseries_* viz_types and require a temporal '
					. 'axis. A categorical x-axis will break the chart. Usually '
					. 'the same as `temporalColumn`. If omitted, the first '
					. 'dimension is used.',
				'filters' => 'Filter expressions applied to the dataset. '
					. 'IMPORTANT — date/datetime columns: use ONLY operator '
					. '`between` for filtering by time. Operators `<`/`>`/`=`/`!=` '
					. 'compare as strings and break on Trino/Presto datasources.',
				'filters[].column' => 'Column to filter on.',
				'filters[].operator' => 'Comparison operator. Use `between` '
					. 'for date/datetime columns — value is either a '
					. 'Superset time expression string ("Last 6 months", '
					. '"2024-01-01 : 2024-03-31", "No filter") or a '
					. '`[from, to]` array of two ISO strings.'
					. ' Optional; defaults to =. Aliases == and <> are also accepted.',
				'filters[].value' => 'Comparison value. Use array for '
					. '`in`/`not_in`. Omit for `is_null`/`is_not_null`. '
					. 'For `between`: a Superset time expression string '
					. 'or a [from, to] pair.'
					. ' Other operators accept recursive JSON, including dictionaries and null.',
				'rowLimit' => 'Row limit for the underlying query. Defaults depend on vizType '
					. '(pie=100, table=1000, otherwise 10000). Integer inputs are clamped to 1..50000.',
			],
		);
		$schema['properties']['confirm'] = [
			'type' => 'boolean',
			'description' => 'Set to true ONLY after the user has explicitly approved '
				. 'overwriting this chart config. Omit or set false to only preview. '
				. 'Do NOT self-confirm or assume approval from earlier context.',
		];

		return $schema;
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		if (!(new FlagGate())->isEnabled(FlagGate::BUILDER_MCP_OPERATIONS, $userId))
		{
			return $this->legacyExecuteStructured($userId, ...$args);
		}

		self::requireBiconnectorModule();

		$chartId = (int)($args['chartId'] ?? 0);
		$config = array_diff_key($args, array_flip(['chartId', 'confirm']));
		$actor = new Actor($userId, Channel::Mcp);
		// Resolved per call: aiassistant keeps a tool as a process-wide singleton.
		$operation = ServiceLocator::getInstance()->get(ReplaceChartConfigOperation::class);

		$confirm = ($args['confirm'] ?? false) === true;
		if (!$confirm)
		{
			$preview = $operation->preview($chartId, $config, $actor);
			if (!$preview->isSuccess())
			{
				throw self::toMcpException($preview);
			}

			return
				[
					'requiresConfirmation' => true,
					'action' => 'replace_chart_config',
				]
				+ $preview->getData()
				+ [
					'impact' => 'The chart configuration will be FULLY overwritten (visualization '
						. 'type, dataset, metrics, dimensions, xAxis, filters, rowLimit and the '
						. 'temporal column). Any field not re-passed reverts to defaults, not to '
						. 'the chart\'s previous values. The chart name and description are kept.',
					'confirmHint' => 'Show this preview to the user; only AFTER they explicitly approve, call '
						. 'replace_chart_config again with the same arguments and confirm=true.',
				]
			;
		}

		$result = $operation->execute($chartId, $config, $actor);
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

		$chartId = (int)($args['chartId'] ?? 0);
		if ($chartId <= 0)
		{
			throw new McpException('chartId is required and must be a positive integer.');
		}

		$accessController = $this->getAccessController($userId);
		if (!$accessController->check(ActionDictionary::ACTION_BIC_ACCESS))
		{
			throw new McpException('Access denied: ACTION_BIC_ACCESS is required.');
		}

		// ChartParamsBuilder::build() requires `name` even though we'll discard
		// `slice_name` from the payload — renaming is UpdateChartMetadataTool's job.
		$args['name'] = '__placeholder__';
		$payload = ChartParamsBuilder::build($args);
		unset($payload['slice_name']);

		$integrator = IntegratorFactory::getInstance();

		// Load the chart once: needed to enforce the object-level write scope
		// (editable only by a user with EDIT on one of its consumer dashboards, as
		// in the web UI) and to report the (unchanged) name. replace_chart_config
		// never renames — updateChart()
		// PATCHes only the params blob and drops slice_name (renaming is
		// UpdateChartMetadataTool's job) — so the pre-update name is the correct
		// value to echo back on both the preview and the confirmed call.
		$user = self::findActAsUser($userId);
		$lookup = (new EntityLookup())->chart($chartId, $user);
		if (!$lookup->isSuccess())
		{
			throw self::toMcpException(OperationRefusal::forTool($lookup));
		}

		$chartData = $lookup->getData()['entity'];
		if (!is_array($chartData['dashboards'] ?? []))
		{
			throw self::unavailableBiException([], ['stage' => 'chart_lookup.dashboards', 'chart_id' => $chartId]);
		}

		$name = (string)($chartData['slice_name'] ?? $chartData['chart_name'] ?? '');

		// Object-level scope: only a user who can edit one of the chart's consumer
		// dashboards may overwrite its config (matches the web UI).
		$this->assertChartEditableByDashboards(
			$userId,
			$chartData['dashboards'] ?? [],
		);

		$dataset = (new ChartDatasetGuard())->validate($args, $user);
		if (!$dataset->isSuccess())
		{
			throw self::toMcpException(OperationRefusal::forTool($dataset));
		}

		$confirm = ($args['confirm'] ?? false) === true;
		if (!$confirm)
		{
			return [
				'requiresConfirmation' => true,
				'action' => 'replace_chart_config',
				'id' => $chartId,
				'name' => $name,
				'newVizType' => (string)($payload['viz_type'] ?? ($args['vizType'] ?? '')),
				'newDatasetId' => (int)($args['datasetId'] ?? 0),
				'impact' => 'The chart configuration will be FULLY overwritten (visualization '
					. 'type, dataset, metrics, dimensions, xAxis, filters, rowLimit and the '
					. 'temporal column). Any field not re-passed reverts to defaults, not to '
					. 'the chart\'s previous values. The chart name and description are kept.',
				'confirmHint' => 'Show this preview to the user; only AFTER they explicitly approve, call replace_chart_config again with the same arguments and confirm=true.',
			];
		}

		$response = $integrator->updateChart($chartId, $payload);
		$classified = (new EntityLookup())->classify($response, $chartId);
		if (!$classified->isSuccess())
		{
			throw self::toMcpException(OperationRefusal::forTool($classified));
		}

		$data = $response->getData();
		if (!is_array($data))
		{
			$data = [];
		}

		// $name comes from the pre-update fetch above: updateChart() drops
		// slice_name, so the response never echoes the name back.
		return [
			'id' => $chartId,
			'name' => $name,
			'viz_type' => (string)($data['viz_type'] ?? $payload['viz_type'] ?? ''),
		];
	}
}

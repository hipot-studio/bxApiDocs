<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Chart;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Main\Web\Json;

/**
 * Builds Superset chart payload from a high-level DSL produced by AI tools.
 *
 * Supported vizType (DSL → Superset internal):
 *   bar         → echarts_timeseries_bar
 *   line        → echarts_timeseries_line
 *   scatter     → echarts_timeseries_scatter
 *   pie         → pie
 *   table       → table
 *   big_number  → big_number_total
 */
final class ChartParamsBuilder
{
	public const VIZ_TYPES = ['bar', 'line', 'scatter', 'pie', 'table', 'big_number'];

	private const VIZ_TYPE_MAP = [
		'bar' => 'echarts_timeseries_bar',
		'line' => 'echarts_timeseries_line',
		'scatter' => 'echarts_timeseries_scatter',
		'pie' => 'pie',
		'table' => 'table',
		'big_number' => 'big_number_total',
	];

	private const AGGREGATES = ['SUM', 'AVG', 'COUNT', 'COUNT_DISTINCT', 'MIN', 'MAX'];

	private const FILTER_OPERATORS = [
		'=' => '==',
		'==' => '==',
		'!=' => '!=',
		'<>' => '!=',
		'<' => '<',
		'>' => '>',
		'<=' => '<=',
		'>=' => '>=',
		'in' => 'IN',
		'not_in' => 'NOT IN',
		'like' => 'LIKE',
		'is_null' => 'IS NULL',
		'is_not_null' => 'IS NOT NULL',
		'between' => 'TEMPORAL_RANGE',
	];

	/**
	 * @param array $dsl {
	 *   datasetId: int (required),
	 *   vizType: string (required, one of self::VIZ_TYPES),
	 *   name: string (required),
	 *   temporalColumn: string (required) — date/datetime column the chart binds
	 *     to so the dashboard time_range filter can rewrite its TEMPORAL_RANGE
	 *     adhoc filter (Superset's merge_extra_form_data path).
	 *   description?: string,
	 *   metrics?: [{column: string, aggregate: string, label?: string}],
	 *   dimensions?: string[],
	 *   xAxis?: string,
	 *   filters?: [{column: string, operator: string, value?: mixed}],
	 *   rowLimit?: int,
	 *   ownerId?: int,
	 * }
	 *
	 * @param (\Closure(string): \Throwable)|null $refusal What an invalid DSL is thrown as, given its description;
	 *     McpException by default.
	 *
	 * @return array Payload for Superset POST /chart/.
	 * @throws McpException on invalid DSL, unless another refusal is given.
	 */
	public static function build(array $dsl, ?\Closure $refusal = null): array
	{
		$refusal ??= static fn(string $message): \Throwable => new McpException($message);

		$datasetId = (int)($dsl['datasetId'] ?? 0);
		if ($datasetId <= 0)
		{
			throw $refusal('datasetId is required and must be a positive integer.');
		}

		$vizTypeAlias = (string)($dsl['vizType'] ?? '');
		if (!isset(self::VIZ_TYPE_MAP[$vizTypeAlias]))
		{
			throw $refusal(
				'Unknown vizType "' . $vizTypeAlias . '". Allowed: ' . implode(', ', self::VIZ_TYPES) . '.'
			);
		}
		$internalVizType = self::VIZ_TYPE_MAP[$vizTypeAlias];

		$name = trim((string)($dsl['name'] ?? ''));
		if ($name === '')
		{
			throw $refusal('Chart name is required.');
		}

		$temporalColumn = trim((string)($dsl['temporalColumn'] ?? ''));
		if ($temporalColumn === '')
		{
			throw $refusal(
				'temporalColumn is required: every chart must bind to the dashboard '
				. 'date filter via a TEMPORAL_RANGE filter on a date/datetime column. '
				. 'Pass the real column name from the dataset (e.g. CREATED_DATE).'
			);
		}

		$metrics = self::normalizeMetrics($dsl['metrics'] ?? [], $refusal);
		$dimensions = self::normalizeStringList($dsl['dimensions'] ?? [], 'dimensions', $refusal);
		$filters = self::normalizeFilters($dsl['filters'] ?? [], $refusal);
		$rowLimit = self::normalizeRowLimit($dsl['rowLimit'] ?? null, $vizTypeAlias);

		$params = self::buildParams(
			$internalVizType,
			$vizTypeAlias,
			$datasetId,
			$metrics,
			$dimensions,
			$filters,
			$rowLimit,
			(string)($dsl['xAxis'] ?? ''),
			$temporalColumn,
			$refusal,
		);

		$payload = [
			'slice_name' => $name,
			'viz_type' => $internalVizType,
			'datasource_id' => $datasetId,
			'datasource_type' => 'table',
			'params' => Json::encode($params),
			'query_context_generation' => true,
		];

		if (!empty($dsl['description']) && is_string($dsl['description']))
		{
			$payload['description'] = $dsl['description'];
		}

		if (isset($dsl['ownerId']) && (int)$dsl['ownerId'] > 0)
		{
			$payload['owners'] = [(int)$dsl['ownerId']];
		}

		return $payload;
	}

	private static function buildParams(
		string $internalVizType,
		string $vizTypeAlias,
		int $datasetId,
		array $metrics,
		array $dimensions,
		array $filters,
		int $rowLimit,
		string $xAxis,
		string $temporalColumn,
		\Closure $refusal,
	): array
	{
		$datasource = $datasetId . '__table';
		$adhocFilters = array_map(
			static fn(array $filter): array => self::buildAdhocFilter($filter, $refusal),
			$filters,
		);

		// Every chart must react to the dashboard date filter. Superset's
		// merge_extra_form_data (utils/core.py) rewrites the comparator of any
		// adhoc filter with operator=TEMPORAL_RANGE from the dashboard's
		// extra_form_data.time_range. No TEMPORAL_RANGE adhoc on the chart →
		// dashboard time_range is silently dropped and the chart returns
		// unfiltered SQL. Inject a "No filter" placeholder on the temporal
		// column up front so the rewrite path has something to target.
		$hasTemporalOnColumn = false;
		foreach ($adhocFilters as $adhoc)
		{
			if (
				($adhoc['operator'] ?? '') === 'TEMPORAL_RANGE'
				&& ($adhoc['subject'] ?? '') === $temporalColumn
			)
			{
				$hasTemporalOnColumn = true;
				break;
			}
		}
		if (!$hasTemporalOnColumn)
		{
			array_unshift($adhocFilters, self::buildTemporalRangePlaceholder($temporalColumn));
		}

		switch ($vizTypeAlias)
		{
			case 'bar':
			case 'line':
			case 'scatter':
				if (empty($metrics))
				{
					throw $refusal($vizTypeAlias . ' chart requires at least one metric.');
				}
				$xAxisColumn = $xAxis !== '' ? $xAxis : (string)($dimensions[0] ?? '');
				if ($xAxisColumn === '')
				{
					throw $refusal($vizTypeAlias . ' chart requires xAxis (or at least one dimension).');
				}
				$groupby = array_values(array_filter(
					$dimensions,
					static fn ($d): bool => $d !== $xAxisColumn,
				));

				$params = [
					'datasource' => $datasource,
					'viz_type' => $internalVizType,
					'x_axis' => $xAxisColumn,
					'metrics' => $metrics,
					'groupby' => $groupby,
					'adhoc_filters' => $adhocFilters,
					'row_limit' => $rowLimit,
					'order_desc' => true,
					'show_legend' => true,
					'legendType' => 'scroll',
					'legendOrientation' => 'top',
					'color_scheme' => 'supersetColors',
					'y_axis_format' => 'SMART_NUMBER',
					'x_axis_time_format' => 'smart_date',
					'rich_tooltip' => true,
					'tooltipTimeFormat' => 'smart_date',
					'truncate_metric' => true,
					'show_empty_columns' => true,
					'extra_form_data' => (object)[],
				];

				// echarts_timeseries_scatter shares buildQuery/transformProps with
				// bar/line (Regular Timeseries family); it only renders points and
				// honours markerSize. See superset-frontend .../Timeseries/Regular/Scatter.
				if ($vizTypeAlias === 'scatter')
				{
					$params['markerSize'] = 6;
				}

				return $params;

			case 'pie':
				if (empty($metrics))
				{
					throw $refusal('pie chart requires exactly one metric.');
				}
				if (empty($dimensions))
				{
					throw $refusal('pie chart requires at least one dimension.');
				}

				return [
					'datasource' => $datasource,
					'viz_type' => $internalVizType,
					'metric' => $metrics[0],
					'groupby' => $dimensions,
					'adhoc_filters' => $adhocFilters,
					'row_limit' => $rowLimit,
					'sort_by_metric' => true,
					'color_scheme' => 'supersetColors',
					'show_legend' => true,
					'legendType' => 'scroll',
					'legendOrientation' => 'top',
					'label_type' => 'key',
					'number_format' => 'SMART_NUMBER',
					'date_format' => 'smart_date',
					'show_labels' => true,
					'labels_outside' => true,
					'outerRadius' => 70,
					'innerRadius' => 30,
					'extra_form_data' => (object)[],
				];

			case 'table':
				if (empty($metrics))
				{
					return [
						'datasource' => $datasource,
						'viz_type' => $internalVizType,
						'query_mode' => 'raw',
						'all_columns' => $dimensions,
						'adhoc_filters' => $adhocFilters,
						'row_limit' => $rowLimit,
						'server_page_length' => 10,
						'order_desc' => true,
						'table_timestamp_format' => 'smart_date',
						'allow_render_html' => false,
						'extra_form_data' => (object)[],
					];
				}

				return [
					'datasource' => $datasource,
					'viz_type' => $internalVizType,
					'query_mode' => 'aggregate',
					'metrics' => $metrics,
					'groupby' => $dimensions,
					'adhoc_filters' => $adhocFilters,
					'row_limit' => $rowLimit,
					'server_page_length' => 10,
					'order_desc' => true,
					'table_timestamp_format' => 'smart_date',
					'allow_render_html' => false,
					'extra_form_data' => (object)[],
				];

			case 'big_number':
				if (empty($metrics))
				{
					throw $refusal('big_number chart requires exactly one metric.');
				}

				return [
					'datasource' => $datasource,
					'viz_type' => $internalVizType,
					'metric' => $metrics[0],
					'adhoc_filters' => $adhocFilters,
					'header_font_size' => 0.4,
					'subheader_font_size' => 0.15,
					'y_axis_format' => 'SMART_NUMBER',
					'time_format' => 'smart_date',
					'extra_form_data' => (object)[],
				];
		}

		throw $refusal('Unsupported vizType: ' . $vizTypeAlias);
	}

	private static function normalizeMetrics(mixed $raw, \Closure $refusal): array
	{
		if (!is_array($raw))
		{
			throw $refusal('metrics must be an array.');
		}

		$result = [];
		foreach ($raw as $idx => $metric)
		{
			if (!is_array($metric))
			{
				throw $refusal('metrics[' . $idx . '] must be an object.');
			}

			$column = trim((string)($metric['column'] ?? ''));
			$aggregate = strtoupper(trim((string)($metric['aggregate'] ?? '')));

			if ($column === '')
			{
				throw $refusal('metrics[' . $idx . '].column is required.');
			}
			if (!in_array($aggregate, self::AGGREGATES, true))
			{
				throw $refusal(
					'metrics[' . $idx . '].aggregate must be one of: ' . implode(', ', self::AGGREGATES) . '.'
				);
			}

			$label = trim((string)($metric['label'] ?? ''));
			$hasCustomLabel = $label !== '';
			if (!$hasCustomLabel)
			{
				$label = $aggregate . '(' . $column . ')';
			}

			$result[] = [
				'expressionType' => 'SIMPLE',
				'column' => [
					'column_name' => $column,
				],
				'aggregate' => $aggregate,
				'hasCustomLabel' => $hasCustomLabel,
				'label' => $label,
				'sqlExpression' => null,
				'datasourceWarning' => false,
			];
		}

		return $result;
	}

	private static function normalizeStringList(mixed $raw, string $fieldName, \Closure $refusal): array
	{
		if (!is_array($raw))
		{
			throw $refusal($fieldName . ' must be an array of strings.');
		}

		$result = [];
		foreach ($raw as $idx => $value)
		{
			if (!is_string($value))
			{
				throw $refusal($fieldName . '[' . $idx . '] must be a string.');
			}
			$trimmed = trim($value);
			if ($trimmed === '')
			{
				continue;
			}
			$result[] = $trimmed;
		}

		return array_values(array_unique($result));
	}

	private static function normalizeFilters(mixed $raw, \Closure $refusal): array
	{
		if (!is_array($raw))
		{
			throw $refusal('filters must be an array.');
		}

		$result = [];
		foreach ($raw as $idx => $filter)
		{
			if (!is_array($filter))
			{
				throw $refusal('filters[' . $idx . '] must be an object.');
			}

			$column = trim((string)($filter['column'] ?? ''));
			$operatorRaw = strtolower(trim((string)($filter['operator'] ?? '=')));

			if ($column === '')
			{
				throw $refusal('filters[' . $idx . '].column is required.');
			}
			if (!isset(self::FILTER_OPERATORS[$operatorRaw]))
			{
				throw $refusal(
					'filters[' . $idx . '].operator must be one of: '
					. implode(', ', array_keys(self::FILTER_OPERATORS)) . '.'
				);
			}

			$result[] = [
				'column' => $column,
				'operator' => self::FILTER_OPERATORS[$operatorRaw],
				'value' => $filter['value'] ?? null,
			];
		}

		return $result;
	}

	private static function buildAdhocFilter(array $filter, \Closure $refusal): array
	{
		if ($filter['operator'] === 'TEMPORAL_RANGE')
		{
			return [
				'clause' => 'WHERE',
				'subject' => $filter['column'],
				'operator' => 'TEMPORAL_RANGE',
				'comparator' => self::normalizeTemporalRangeValue($filter['value'], $filter['column'], $refusal),
				'expressionType' => 'SIMPLE',
			];
		}

		return [
			'clause' => 'WHERE',
			'subject' => $filter['column'],
			'operator' => $filter['operator'],
			'comparator' => $filter['value'],
			'expressionType' => 'SIMPLE',
		];
	}

	/**
	 * Coerce a `between` filter value into a Superset time-range expression.
	 * Accepts:
	 *   - string  — used as-is ("Last 6 months", "2024-01-01 : 2024-03-31", "No filter")
	 *   - array of two scalars — joined as "from : to"
	 */
	private static function normalizeTemporalRangeValue(mixed $value, string $column, \Closure $refusal): string
	{
		if (is_string($value))
		{
			$trimmed = trim($value);
			if ($trimmed === '')
			{
				throw $refusal(
					'Filter on `' . $column . '` with operator=between requires a non-empty value '
					. '(e.g. "Last 6 months" or "2024-01-01 : 2024-03-31").'
				);
			}

			return $trimmed;
		}

		if (is_array($value))
		{
			if (count($value) !== 2)
			{
				throw $refusal(
					'Filter on `' . $column . '` with operator=between requires value to be '
					. 'a [from, to] array of two strings or a single Superset time expression string.'
				);
			}
			$from = trim((string)$value[array_key_first($value)]);
			$to = trim((string)$value[array_key_last($value)]);
			if ($from === '' || $to === '')
			{
				throw $refusal(
					'Filter on `' . $column . '` with operator=between: both `from` and `to` must be non-empty.'
				);
			}

			return $from . ' : ' . $to;
		}

		throw $refusal(
			'Filter on `' . $column . '` with operator=between requires a string value or '
			. '[from, to] array.'
		);
	}

	private static function buildTemporalRangePlaceholder(string $column): array
	{
		return [
			'clause' => 'WHERE',
			'subject' => $column,
			'operator' => 'TEMPORAL_RANGE',
			'comparator' => 'No filter',
			'expressionType' => 'SIMPLE',
		];
	}

	private static function normalizeRowLimit(mixed $raw, string $vizTypeAlias): int
	{
		$default = match ($vizTypeAlias) {
			'pie' => 100,
			'table' => 1000,
			'big_number' => 10000,
			default => 10000,
		};

		if ($raw === null || $raw === '')
		{
			return $default;
		}

		$value = (int)$raw;

		return max(1, min(50000, $value));
	}
}

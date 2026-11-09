<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\BIConnector\Internal\Operation\Builder\Argument\BuilderArgumentContract;
use Bitrix\BIConnector\Internal\Operation\Builder\Argument\BuilderArgumentGuard;
use Bitrix\BIConnector\Internal\Operation\Builder\Argument\BuilderArgumentNormalizer;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\Main\Result;

final class BuilderToolArguments
{
	private const CONFIRMED_TOOLS = [
		'update_dashboard_metadata',
		'set_dashboard_content',
		'delete_dashboard',
		'update_chart_metadata',
		'replace_chart_config',
		'delete_chart',
		'update_dataset',
		'delete_dataset',
	];

	/**
	 * Paging arguments: the tools clamped them before the common guard, so a refusal names the range instead.
	 */
	private const PAGING = ['limit', 'rowLimit', 'offset'];

	private const DASHBOARD_ID = 'dashboardId is required and must be a positive integer.';
	private const CHART_ID = 'chartId is required and must be a positive integer.';
	private const DATASET_ID = 'datasetId is required and must be a positive integer.';
	private const NAME = 'name is required and must be a non-empty string.';
	private const DATASET_NAME = 'datasetName is required: provide a unique name for the new dataset.';
	private const COLUMNS = 'At least one column must be provided.';

	/**
	 * The texts the tools refused a broken facet of a root argument with before the common guard took the check over.
	 */
	private const FACET_REFUSALS = [
		'create_dashboard' => ['name.minLength' => self::NAME],
		'update_dashboard_metadata' => ['dashboardId.minimum' => self::DASHBOARD_ID, 'name.minLength' => self::NAME],
		'set_dashboard_content' => ['dashboardId.minimum' => self::DASHBOARD_ID],
		'delete_dashboard' => ['dashboardId.minimum' => self::DASHBOARD_ID],
		'get_chart' => ['chartId.minimum' => self::CHART_ID],
		'create_chart' => ['name.minLength' => self::NAME, 'datasetId.minimum' => self::DATASET_ID],
		'update_chart_metadata' => [
			'chartId.minimum' => self::CHART_ID,
			'name.minLength' => '`name` must be a non-empty string. Omit it to keep the current name.',
		],
		'replace_chart_config' => ['chartId.minimum' => self::CHART_ID, 'datasetId.minimum' => self::DATASET_ID],
		'delete_chart' => ['chartId.minimum' => self::CHART_ID],
		'get_chart_data' => [
			'chartIds.minItems' => 'chartIds must be a non-empty array of chart ids.',
			'chartIds.x-max-unique-items' => 'Too many charts requested: max 5 per call. '
				. 'Reduce `chartIds` to the most relevant charts for the user\'s question. '
				. 'If you must ask the user to narrow down, refer to charts BY THEIR `name` '
				. 'from the previous get_dashboard_meta response — never quote raw chart ids, '
				. 'they are an internal id and confuse the user.',
		],
		'get_dataset' => ['datasetId.minimum' => self::DATASET_ID],
		'create_dataset_from_sql' => [
			'datasetName.minLength' => self::DATASET_NAME,
			'sql.minLength' => 'sql is required: provide the SELECT query that defines the dataset.',
		],
		'create_virtual_dataset' => [
			'datasetName.minLength' => self::DATASET_NAME,
			'sourceTable.minLength' => 'sourceTable is required: provide the source table name '
				. '(call list_bi_tables to see available tables).',
			'columns.minItems' => self::COLUMNS,
		],
		'update_dataset' => ['datasetId.minimum' => self::DATASET_ID, 'columns.minItems' => self::COLUMNS],
		'delete_dataset' => ['datasetId.minimum' => self::DATASET_ID],
	];

	public static function retainKnown(string $tool, array $args): array
	{
		$method = BuilderArgumentContract::methodForTool($tool);
		if ($method === null)
		{
			return $args;
		}
		$known = BuilderArgumentContract::forMethod($method)['properties'];
		if (in_array($tool, self::CONFIRMED_TOOLS, true))
		{
			$known['confirm'] = true;
		}

		return array_intersect_key($args, $known);
	}

	public static function prepare(string $tool, array $args): Result
	{
		$method = BuilderArgumentContract::methodForTool($tool);
		$args = self::retainKnown($tool, $args);
		$control = [];
		if (in_array($tool, self::CONFIRMED_TOOLS, true) && array_key_exists('confirm', $args))
		{
			if (!is_bool($args['confirm']))
			{
				return Refusal::error(FailureCode::InvalidArgument, 'Invalid BI Builder confirmation.');
			}
			$control['confirm'] = $args['confirm'];
			unset($args['confirm']);
		}
		if ($method !== null)
		{
			$result = BuilderArgumentGuard::validate($method, $args);
			if (!$result->isSuccess())
			{
				$message = self::FACET_REFUSALS[$tool][$result->getData()['argumentViolation'] ?? ''] ?? null;
				$message ??= self::previousRuleRefusal($result->getData());
				$message ??= self::rangeRefusal($method, $args);
				if ($message !== null)
				{
					return Refusal::error(FailureCode::InvalidArgument, $message);
				}

				return $result;
			}
			$args = BuilderArgumentNormalizer::normalize($method, $args);
		}

		return (new Result())->setData(['business' => $args, 'control' => $control]);
	}

	/**
	 * Names the paging argument that is out of its range, so the agent can fix the call.
	 */
	private static function rangeRefusal(string $method, array $args): ?string
	{
		$properties = BuilderArgumentContract::forMethod($method)['properties'] ?? [];
		foreach (self::PAGING as $name)
		{
			$property = $properties[$name] ?? [];
			$value = $args[$name] ?? null;
			if (!is_int($value) || ($property['type'] ?? '') !== 'integer')
			{
				continue;
			}
			$minimum = $property['minimum'] ?? null;
			$maximum = $property['maximum'] ?? null;
			if (($minimum !== null && $value < $minimum) || ($maximum !== null && $value > $maximum))
			{
				return match (true)
				{
					$minimum !== null && $maximum !== null => "`{$name}` must be an integer from {$minimum} to {$maximum}.",
					$minimum !== null => "`{$name}` must be an integer not less than {$minimum}.",
					default => "`{$name}` must be an integer not greater than {$maximum}.",
				};
			}
		}

		return null;
	}

	/**
	 * The text the tool refused the broken rule with before the common guard took the check over.
	 */
	private static function previousRuleRefusal(array $violation): ?string
	{
		$column = $violation['column'] ?? '';

		return match ($violation['argumentViolation'] ?? null)
		{
			'content.nothing' => 'Nothing to update. Provide at least one of: charts, filters. '
				. 'For rename use update_dashboard_metadata; for publish/unpublish use '
				. 'publish_dashboard / unpublish_dashboard.',
			'content.filters.datasetId' => 'filters[' . $violation['index'] . '].datasetId '
				. 'is required for filterType=' . $violation['filterType'] . '.',
			'content.filters.column' => 'filters[' . $violation['index'] . '].column '
				. 'is required for filterType=' . $violation['filterType'] . '.',
			'content.filters.period' => 'filters must contain at least one `time_range` filter with a non-empty '
				. '`defaultValue`. Every dashboard needs a date filter; if the user did '
				. 'not specify a period, default to "Last quarter".',
			'chart.update.nothing' => 'Nothing to update. Provide at least one of `name` or `description`. '
				. 'To modify the chart configuration use `replace_chart_config` instead.',
			'tableName.blank' => 'tableName is required: provide the table name '
				. '(call list_bi_tables to see available tables).',
			'columns.blank' => 'At least one non-empty column must be provided.',
			'temporalColumn.blank' => 'temporalColumn is required: every chart must bind to the dashboard '
				. 'date filter via a TEMPORAL_RANGE filter on a date/datetime column. '
				. 'Pass the real column name from the dataset (e.g. CREATED_DATE).',
			'metrics.required' => match ($violation['vizType'])
			{
				'bar', 'line', 'scatter' => $violation['vizType'] . ' chart requires at least one metric.',
				'pie', 'big_number' => $violation['vizType'] . ' chart requires exactly one metric.',
				default => null,
			},
			'dimensions.required' => $violation['vizType'] === 'pie'
				? 'pie chart requires at least one dimension.'
				: $violation['vizType'] . ' chart requires xAxis (or at least one dimension).',
			'between.blank' => 'Filter on `' . $column . '` with operator=between requires a non-empty value '
				. '(e.g. "Last 6 months" or "2024-01-01 : 2024-03-31").',
			'between.pair' => 'Filter on `' . $column . '` with operator=between requires value to be '
				. 'a [from, to] array of two strings or a single Superset time expression string.',
			'between.bounds' => 'Filter on `' . $column . '` with operator=between: '
				. 'both `from` and `to` must be non-empty.',
			'between.type' => 'Filter on `' . $column . '` with operator=between requires a string value or '
				. '[from, to] array.',
			default => null,
		};
	}
}

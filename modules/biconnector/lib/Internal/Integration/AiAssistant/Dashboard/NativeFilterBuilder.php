<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Dashboard;

use Bitrix\AiAssistant\Exceptions\McpException;

/**
 * Builds Superset `json_metadata.native_filter_configuration` from a high-level filter DSL.
 *
 * DSL form: a flat list of filters [{name, filterType, column?, datasetId?, chartIds?, defaultValue?}].
 * Supported `filterType` aliases (DSL → Superset internal):
 *   - time_range → filter_time (global, no dataset/column binding required;
 *                  `defaultValue` is a Superset time expression like "Last quarter"
 *                  or "2024-01-01 : 2024-03-31")
 *   - value      → filter_select (multi-select dropdown by column value)
 *   - range      → filter_range  (numeric range on a column)
 */
final class NativeFilterBuilder
{
	public const FILTER_TYPES = ['time_range', 'value', 'range'];

	private const FILTER_TYPE_MAP = [
		'time_range' => 'filter_time',
		'value' => 'filter_select',
		'range' => 'filter_range',
	];

	/**
	 * @param array $filters DSL filter list.
	 * @param int[] $defaultChartsInScope Fallback chart IDs to apply each filter to
	 *                                    when the filter itself doesn't specify chartIds.
	 * @param (\Closure(string): \Throwable)|null $refusal What a malformed filter is thrown as, given its
	 *     description; McpException by default.
	 *
	 * @return array native_filter_configuration[] for json_metadata.
	 *
	 * @throws McpException on invalid input, unless another refusal is given.
	 */
	public static function build(array $filters, array $defaultChartsInScope = [], ?\Closure $refusal = null): array
	{
		$refusal ??= static fn(string $message): \Throwable => new McpException($message);

		$result = [];
		foreach ($filters as $idx => $filter)
		{
			$result[] = self::buildOne($filter, $idx, $defaultChartsInScope, $refusal);
		}

		return $result;
	}

	private static function buildOne(mixed $filter, int $idx, array $defaultChartsInScope, \Closure $refusal): array
	{
		if (!is_array($filter))
		{
			throw $refusal('filters[' . $idx . '] must be an object.');
		}

		$name = trim((string)($filter['name'] ?? ''));
		if ($name === '')
		{
			throw $refusal('filters[' . $idx . '].name is required.');
		}

		$alias = (string)($filter['filterType'] ?? '');
		if (!isset(self::FILTER_TYPE_MAP[$alias]))
		{
			throw $refusal(
				'filters[' . $idx . '].filterType must be one of: '
				. implode(', ', self::FILTER_TYPES) . '.'
			);
		}
		$internalType = self::FILTER_TYPE_MAP[$alias];

		$chartIds = self::normalizeChartIds(
			$filter['chartIds'] ?? null,
			$idx,
			$defaultChartsInScope,
			$refusal,
		);

		// Stable id derived from filter identity so re-running set_dashboard_content
		// with the same DSL produces an identical native_filter_configuration. Random
		// ids would invalidate deeplinks (`native_filters_key=...`), Redux saved
		// state and cross-filter targeting across updates.
		$filterId = self::buildFilterId(
			$alias,
			$internalType,
			$name,
			(int)($filter['datasetId'] ?? 0),
			trim((string)($filter['column'] ?? '')),
		);

		$base = [
			'id' => $filterId,
			'name' => $name,
			'filterType' => $internalType,
			'type' => 'NATIVE_FILTER',
			'description' => '',
			'cascadeParentIds' => [],
			'defaultDataMask' => [
				'extraFormData' => (object)[],
				'filterState' => (object)[],
				'ownState' => (object)[],
			],
			'scope' => [
				'rootPath' => ['ROOT_ID'],
				'excluded' => [],
			],
			'chartsInScope' => $chartIds,
		];

		if ($alias === 'time_range')
		{
			// A global time-range filter has no column binding, but Superset's
			// canonical target shape is still `targets: [{}]` — a list with a
			// single EMPTY OBJECT, never an empty list. The frontend filter bar
			// destructures `targets[0]` (FilterValue.tsx: `const [target] = targets;
			// const { datasetId } = target;`) and throws on `undefined` if the list
			// is empty; the /overview backend reads `targets[0].get("column")`.
			// The empty `{}` is corrupted to `[]` on the proxy HTTP boundary (PHP
			// assoc-array round-trip) and restored to `{}` at the final encode by
			// DashboardService::normalizeJsonMetadataObjectFields().
			$base['targets'] = [(object)[]];
			$base['controlValues'] = (object)[];

			$defaultValue = isset($filter['defaultValue']) && is_string($filter['defaultValue'])
				? trim($filter['defaultValue'])
				: ''
			;
			if ($defaultValue !== '')
			{
				$base['defaultDataMask'] = [
					'extraFormData' => ['time_range' => $defaultValue],
					'filterState' => ['value' => $defaultValue],
					'ownState' => (object)[],
				];
			}

			return $base;
		}

		$datasetId = (int)($filter['datasetId'] ?? 0);
		$column = trim((string)($filter['column'] ?? ''));
		if ($datasetId <= 0)
		{
			throw $refusal(
				'filters[' . $idx . '].datasetId is required for filterType=' . $alias . '.'
			);
		}
		if ($column === '')
		{
			throw $refusal(
				'filters[' . $idx . '].column is required for filterType=' . $alias . '.'
			);
		}

		$base['targets'] = [[
			'datasetId' => $datasetId,
			'column' => ['name' => $column],
		]];

		if ($alias === 'value')
		{
			$base['controlValues'] = [
				'enableEmptyFilter' => false,
				'defaultToFirstItem' => false,
				'multiSelect' => true,
				'searchAllOptions' => false,
				'inverseSelection' => false,
			];
		}
		else
		{
			$base['controlValues'] = (object)[];
		}

		return $base;
	}

	/**
	 * @return int[]
	 */
	private static function normalizeChartIds(mixed $raw, int $idx, array $fallback, \Closure $refusal): array
	{
		if ($raw === null)
		{
			return array_values(array_unique(array_map('intval', $fallback)));
		}

		if (!is_array($raw))
		{
			throw $refusal('filters[' . $idx . '].chartIds must be an array of integers.');
		}

		$result = [];
		foreach ($raw as $subIdx => $value)
		{
			$id = (int)$value;
			if ($id <= 0)
			{
				throw $refusal(
					'filters[' . $idx . '].chartIds[' . $subIdx . '] must be a positive integer.'
				);
			}
			$result[] = $id;
		}

		return array_values(array_unique($result));
	}

	private static function buildFilterId(
		string $alias,
		string $internalType,
		string $name,
		int $datasetId,
		string $column,
	): string
	{
		$seed = implode('|', [$alias, $internalType, $name, $datasetId, $column]);

		return 'NATIVE_FILTER-' . substr(hash('sha256', $seed), 0, 20);
	}
}

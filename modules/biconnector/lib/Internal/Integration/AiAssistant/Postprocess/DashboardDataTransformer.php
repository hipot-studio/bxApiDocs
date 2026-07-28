<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Postprocess;

use Bitrix\BIConnector\Superset\Logger\AiToolsLogger;
use Bitrix\Main\Error;

class DashboardDataTransformer
{
	private const FRACTION_SUFFIX = '__contribution';

	private const COLTYPE_NUMERIC = 0;
	private const COLTYPE_STRING = 1;
	private const COLTYPE_TEMPORAL = 2;
	private const COLTYPE_BOOLEAN = 3;

	private const TYPE_DATE = 'date';
	private const TYPE_INTEGER = 'integer';
	private const TYPE_FLOAT = 'float';
	private const TYPE_STRING = 'string';
	private const TYPE_BOOLEAN = 'boolean';

	private const RFC_2822_PATTERN = '/^[A-Z][a-z]{2},\s+\d{1,2}\s+[A-Z][a-z]{2}\s+\d{4}\s+\d{2}:\d{2}:\d{2}\s+\w+$/';

	/** @var array<int, array<string, int>> chartIndex => [colName => colType] */
	private array $chartColTypes = [];

	private TransformerConfig $config;

	public function __construct(TransformerConfig $config)
	{
		$this->config = $config;
	}

	public function transform(array $data): array
	{
		$this->prepare($data);
		$this->cleanup($data);
		$this->enrichCharts($data);
		$this->enrichDashboard($data);
		$this->assemble($data);

		return $data;
	}

	private function prepare(array &$data): void
	{
		foreach ($data['charts'] ?? [] as $idx => $chart)
		{
			$queryResult = $chart['query_result'] ?? null;
			if (!is_array($queryResult) || empty($queryResult[0]))
			{
				continue;
			}

			$colNames = $queryResult[0]['colnames'] ?? [];
			$colTypes = $queryResult[0]['coltypes'] ?? [];
			$map = [];
			foreach ($colNames as $i => $name)
			{
				if (isset($colTypes[$i]))
				{
					$map[$name] = (int)$colTypes[$i];
				}
			}
			$this->chartColTypes[$idx] = $map;
		}
	}

	private function cleanup(array &$data): void
	{
		if (!isset($data['charts']) || !is_array($data['charts']))
		{
			return;
		}

		foreach ($data['charts'] as $idx => $chart)
		{
			try
			{
				if ($this->config->transposeDictData)
				{
					$this->transposeDictData($chart);
				}

				$this->flattenQueryResult($chart);
				$this->removeServiceFields($chart);

				if ($this->config->normalizeDates)
				{
					$this->normalizeDates($chart, $idx);
				}

				$this->scalePercentColumns($chart);

				if ($this->config->roundNumbers)
				{
					$this->roundNumbers($chart, $idx);
				}

				if ($this->config->removeEmptyFields)
				{
					$cleaned = $this->removeEmptyRecursive($chart);
					if (is_array($cleaned))
					{
						$chart = $cleaned;
					}
				}
			}
			catch (\Throwable $e)
			{
				AiToolsLogger::logErrors(
					[new Error($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine())],
					['stage' => 'transformer.cleanup', 'chart_idx' => $idx],
				);
			}

			$data['charts'][$idx] = $chart;
		}
	}

	private function transposeDictData(array &$chart): void
	{
		$queryResult = $chart['query_result'] ?? null;
		if (!is_array($queryResult))
		{
			return;
		}

		foreach ($queryResult as &$qr)
		{
			$qrData = $qr['data'] ?? null;
			if (!is_array($qrData) || empty($qrData))
			{
				continue;
			}

			$firstVal = reset($qrData);
			if (!is_array($firstVal) || array_is_list($qrData))
			{
				continue;
			}

			$keys = array_keys($firstVal);
			sort($keys, SORT_NUMERIC);
			$records = [];
			foreach ($keys as $rowKey)
			{
				$row = [];
				foreach ($qrData as $colName => $colData)
				{
					$row[$colName] = $colData[$rowKey] ?? null;
				}
				$records[] = $row;
			}
			$qr['data'] = $records;
		}
		unset($qr);

		$chart['query_result'] = $queryResult;
	}

	private function flattenQueryResult(array &$chart): void
	{
		$queryResult = $chart['query_result'] ?? null;
		if (is_array($queryResult) && !empty($queryResult[0]['data']))
		{
			$chart['data'] = $queryResult[0]['data'];
		}
		else
		{
			$chart['data'] = [];
		}
	}

	private function removeServiceFields(array &$chart): void
	{
		unset($chart['query_result'], $chart['viz_type']);

		if (!$this->config->preserveChartIds)
		{
			unset($chart['id']);
		}

		if (isset($chart['description']) && $chart['description'] === '')
		{
			unset($chart['description']);
		}
	}

	private function normalizeDates(array &$chart, int $chartIdx): void
	{
		$rows = &$chart['data'];
		if (!is_array($rows) || empty($rows) || !is_array($rows[0]))
		{
			return;
		}

		$colTypes = $this->chartColTypes[$chartIdx] ?? [];

		$dateCols = [];
		foreach ($colTypes as $name => $type)
		{
			if ($type === self::COLTYPE_TEMPORAL)
			{
				$dateCols[$name] = true;
			}
		}

		// Fallback for charts where bx-superset didn't emit coltypes.
		foreach ($rows[0] as $colName => $val)
		{
			if (isset($dateCols[$colName]))
			{
				continue;
			}
			if (is_string($val) && $val !== '' && preg_match(self::RFC_2822_PATTERN, $val))
			{
				$dateCols[$colName] = true;
			}
		}

		if (empty($dateCols))
		{
			return;
		}

		foreach (array_keys($dateCols) as $colName)
		{
			$allMidnight = true;
			$parsedValues = [];

			foreach ($rows as $row)
			{
				$val = $row[$colName] ?? null;
				if (!is_string($val) || $val === '')
				{
					$parsedValues[] = null;

					continue;
				}

				$dt = date_create_immutable($val);
				if ($dt === false)
				{
					$parsedValues[] = null;

					continue;
				}

				$parsedValues[] = $dt;
				if ($dt->format('H:i:s') !== '00:00:00')
				{
					$allMidnight = false;
				}
			}

			$format = $allMidnight ? 'Y-m-d' : 'Y-m-d\TH:i:s';
			foreach ($rows as $i => &$row)
			{
				$dt = $parsedValues[$i] ?? null;
				if ($dt instanceof \DateTimeImmutable)
				{
					$row[$colName] = $dt->format($format);
				}
			}
			unset($row);
		}
	}

	private function scalePercentColumns(array &$chart): void
	{
		// Source of truth is `column_descriptions[col].is_percent`, emitted by
		// the bx-superset /overview endpoint.
		$percentCols = [];
		foreach ($chart['column_descriptions'] ?? [] as $colName => $desc)
		{
			if (is_array($desc) && !empty($desc['is_percent']) && is_string($colName) && $colName !== '')
			{
				$percentCols[] = $colName;
			}
		}

		if (empty($percentCols))
		{
			return;
		}

		$rows = &$chart['data'];
		if (!is_array($rows) || empty($rows) || !is_array($rows[0]))
		{
			return;
		}

		foreach ($percentCols as $colName)
		{
			if (!is_string($colName) || $colName === '')
			{
				continue;
			}

			$allInRatioRange = true;
			$hasNumeric = false;
			foreach ($rows as $row)
			{
				if (!isset($row[$colName]) || !is_numeric($row[$colName]))
				{
					continue;
				}
				$hasNumeric = true;
				$v = (float)$row[$colName];
				if ($v < 0 || $v > 1)
				{
					$allInRatioRange = false;

					break;
				}
			}

			if (!$hasNumeric || !$allInRatioRange)
			{
				// Values are already in percent form or column is empty — do nothing.
				continue;
			}

			foreach ($rows as &$row)
			{
				if (isset($row[$colName]) && is_numeric($row[$colName]))
				{
					$row[$colName] = round((float)$row[$colName] * 100, 2);
				}
			}
			unset($row);

			// Remember for later enrichment of column_descriptions.
			$chart['_scaled_percent_columns'][] = $colName;
		}
	}

	private function roundNumbers(array &$chart, int $chartIdx): void
	{
		$rows = &$chart['data'];
		if (!is_array($rows) || empty($rows) || !is_array($rows[0]))
		{
			return;
		}

		$numericCols = $this->getNumericColumns($rows, $chartIdx);
		if (empty($numericCols))
		{
			return;
		}

		$fractionCols = $this->detectFractionColumns($rows, $numericCols);

		foreach ($rows as &$row)
		{
			foreach ($numericCols as $colName)
			{
				if (!isset($row[$colName]) || !is_numeric($row[$colName]))
				{
					continue;
				}

				$val = $row[$colName];
				if (is_int($val))
				{
					continue;
				}

				$places = isset($fractionCols[$colName])
					? $this->config->fractionDecimalPlaces
					: $this->config->defaultDecimalPlaces;

				$rounded = round($val, $places);
				$row[$colName] = ($rounded == (int)$rounded) ? (int)$rounded : $rounded;
			}
		}
		unset($row);
	}

	private function getNumericColumns(array $rows, int $chartIdx): array
	{
		$colTypes = $this->chartColTypes[$chartIdx] ?? [];
		$numericCols = [];

		foreach ($colTypes as $name => $type)
		{
			if ($type === self::COLTYPE_NUMERIC)
			{
				$numericCols[] = $name;
			}
		}

		if (empty($numericCols) && !empty($rows[0]))
		{
			foreach ($rows[0] as $name => $val)
			{
				if (is_int($val) || is_float($val))
				{
					$numericCols[] = $name;
				}
			}
		}

		return $numericCols;
	}

	/**
	 * @return array<string, true>
	 */
	private function detectFractionColumns(array $rows, array $numericCols): array
	{
		$fractionCols = [];

		foreach ($numericCols as $colName)
		{
			if (str_ends_with($colName, self::FRACTION_SUFFIX))
			{
				$fractionCols[$colName] = true;

				continue;
			}

			$allInRange = true;
			$hasValues = false;
			foreach ($rows as $row)
			{
				$val = $row[$colName] ?? null;
				if ($val === null || !is_numeric($val))
				{
					continue;
				}
				$hasValues = true;
				if ($val < 0 || $val > 1)
				{
					$allInRange = false;

					break;
				}
			}
			if ($hasValues && $allInRange)
			{
				$fractionCols[$colName] = true;
			}
		}

		return $fractionCols;
	}

	private function removeEmptyRecursive(mixed $value): mixed
	{
		if (is_array($value))
		{
			$isList = array_is_list($value);
			$result = [];
			foreach ($value as $k => $v)
			{
				$cleaned = $this->removeEmptyRecursive($v);
				if ($cleaned !== null)
				{
					$result[$k] = $cleaned;
				}
			}

			if (empty($result))
			{
				return null;
			}

			return $isList ? array_values($result) : $result;
		}

		if ($value === null || $value === '' || $value === [])
		{
			return null;
		}

		return $value;
	}

	private function enrichCharts(array &$data): void
	{
		if (!isset($data['charts']) || !is_array($data['charts']))
		{
			return;
		}

		foreach ($data['charts'] as $idx => $chart)
		{
			try
			{
				if ($this->config->generateColumnDescriptions)
				{
					$this->generateColumnDescriptions($chart, $idx);
				}
			}
			catch (\Throwable $e)
			{
				AiToolsLogger::logErrors(
					[new Error($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine())],
					['stage' => 'transformer.enrich_charts', 'chart_idx' => $idx],
				);
			}

			$data['charts'][$idx] = $chart;
		}
	}

	private function generateColumnDescriptions(array &$chart, int $chartIdx): void
	{
		$rows = $chart['data'] ?? [];
		if (empty($rows) || !is_array($rows[0]))
		{
			return;
		}

		$chartName = $chart['name'] ?? '';
		$columnMeta = $chart['column_meta'] ?? [];
		unset($chart['column_meta']);

		$descriptions = [];

		// Resolve in priority order: portal override → Superset verbose_name/description → auto.
		foreach (array_keys($rows[0]) as $colName)
		{
			if (isset($this->config->columnDescriptionsOverride[$chartName][$colName]))
			{
				$descriptions[$colName] = $this->config->columnDescriptionsOverride[$chartName][$colName];

				continue;
			}

			$meta = $columnMeta[$colName] ?? null;
			if ($meta)
			{
				$desc = $meta['description'] ?? $meta['verbose_name'] ?? null;
				if ($desc)
				{
					$descriptions[$colName] = ['type' => $this->detectColumnType($colName, $rows, $chartIdx), 'description' => $desc];

					continue;
				}
			}

			$descriptions[$colName] = $this->autoDescribeColumn($colName, $rows, $chartIdx);
		}

		// Bitrix MCP tool guide caps list-row text at ~200 chars.
		foreach ($descriptions as &$d)
		{
			$text = $d['description'] ?? null;
			if (is_string($text) && mb_strlen($text) > 200)
			{
				$d['description'] = mb_substr($text, 0, 197) . '…';
			}
		}
		unset($d);

		$scaled = $chart['_scaled_percent_columns'] ?? [];
		unset($chart['_scaled_percent_columns']);
		if (is_array($scaled))
		{
			foreach ($scaled as $colName)
			{
				if (isset($descriptions[$colName]) && is_array($descriptions[$colName]))
				{
					$descriptions[$colName]['is_percent'] = true;
				}
			}
		}

		if (!empty($descriptions))
		{
			$chart['column_descriptions'] = $descriptions;
		}
	}

	private function detectColumnType(string $colName, array $rows, int $chartIdx): string
	{
		$colTypes = $this->chartColTypes[$chartIdx] ?? [];
		$type = $colTypes[$colName] ?? -1;

		if ($type === self::COLTYPE_TEMPORAL)
		{
			return self::TYPE_DATE;
		}

		if ($type === self::COLTYPE_NUMERIC)
		{
			$allInt = true;
			foreach ($rows as $row)
			{
				$val = $row[$colName] ?? null;
				if ($val !== null && is_float($val))
				{
					$allInt = false;

					break;
				}
			}

			return $allInt ? self::TYPE_INTEGER : self::TYPE_FLOAT;
		}

		if ($type === self::COLTYPE_STRING)
		{
			return self::TYPE_STRING;
		}

		if ($type === self::COLTYPE_BOOLEAN)
		{
			return self::TYPE_BOOLEAN;
		}

		$firstVal = null;
		foreach ($rows as $row)
		{
			if (isset($row[$colName]))
			{
				$firstVal = $row[$colName];

				break;
			}
		}

		if (is_int($firstVal))
		{
			return self::TYPE_INTEGER;
		}
		if (is_float($firstVal))
		{
			return self::TYPE_FLOAT;
		}

		return self::TYPE_STRING;
	}

	private function autoDescribeColumn(string $colName, array $rows, int $chartIdx): array
	{
		$type = $this->detectColumnType($colName, $rows, $chartIdx);

		$values = [];
		foreach ($rows as $row)
		{
			if (isset($row[$colName]) && $row[$colName] !== null)
			{
				$values[] = $row[$colName];
			}
		}

		if (empty($values))
		{
			return ['type' => $type, 'description' => $colName];
		}

		switch ($type)
		{
			case self::TYPE_DATE:
				$sorted = $values;
				sort($sorted);
				$from = reset($sorted);
				$to = end($sorted);

				return [
					'type' => self::TYPE_DATE,
					'description' => 'Date (from ' . $from . ' to ' . $to . ')',
				];

			case self::TYPE_INTEGER:
			case self::TYPE_FLOAT:
				$min = min($values);
				$max = max($values);

				return [
					'type' => $type,
					'description' => 'Numeric value (range: ' . $min . ' — ' . $max . ')',
				];

			case self::TYPE_BOOLEAN:
				return [
					'type' => self::TYPE_BOOLEAN,
					'description' => 'Boolean value (yes/no)',
				];

			case self::TYPE_STRING:
				$unique = array_unique($values);
				$count = count($unique);
				if ($count <= 20)
				{
					sort($unique);
					$list = implode(', ', $unique);

					return [
						'type' => self::TYPE_STRING,
						'description' => $count === 1
							? 'Category (1 value: ' . $list . ')'
							: 'Category (' . $count . ' values: ' . $list . ')',
					];
				}

				return [
					'type' => self::TYPE_STRING,
					'description' => $count === 1
						? 'Text (1 unique value)'
						: 'Text (' . $count . ' unique values)',
				];

			default:
				return ['type' => $type, 'description' => $colName];
		}
	}

	private function enrichDashboard(array &$data): void
	{
		try
		{
			if ($this->config->includeMeta)
			{
				$this->addMeta($data);
			}

			if (!empty($this->config->appliedFilters))
			{
				$data['applied_filters'] = $this->config->appliedFilters;
			}
		}
		catch (\Throwable $e)
		{
			AiToolsLogger::logErrors(
				[new Error($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine())],
				['stage' => 'transformer.enrich_dashboard'],
			);
		}
	}

	private function addMeta(array &$data): void
	{
		$charts = $data['charts'] ?? [];
		$dashboard = $data['dashboard'] ?? [];

		$allDates = [];
		foreach ($charts as $idx => $chart)
		{
			$colTypes = $this->chartColTypes[$idx] ?? [];
			$dateCols = [];
			foreach ($colTypes as $name => $type)
			{
				if ($type === self::COLTYPE_TEMPORAL)
				{
					$dateCols[] = $name;
				}
			}

			foreach ($chart['data'] ?? [] as $row)
			{
				foreach ($dateCols as $colName)
				{
					$val = $row[$colName] ?? null;
					if (is_string($val) && $val !== '')
					{
						$allDates[] = $val;
					}
				}
			}
		}

		$meta = [
			'dashboard_title' => $dashboard['title'] ?? '',
			'total_charts' => count($charts),
			// Formal hasMore stub required by the Bitrix MCP tool guide for
			// list-like responses; flip to true once real pagination lands.
			'charts_has_more' => false,
		];

		$periodFrom = null;
		$periodTo = null;
		foreach ($this->config->appliedFilters ?? [] as $f)
		{
			if (($f['name'] ?? null) !== 'period')
			{
				continue;
			}
			$value = $f['value'] ?? null;
			if (is_array($value))
			{
				$periodFrom = $value['from'] ?? null;
				$periodTo = $value['to'] ?? null;
			}

			break;
		}

		if (($periodFrom === null || $periodTo === null) && !empty($allDates))
		{
			sort($allDates);
			$periodFrom = reset($allDates);
			$periodTo = end($allDates);
		}

		if ($periodFrom !== null && $periodTo !== null)
		{
			$meta['period'] = [
				'from' => $periodFrom,
				'to' => $periodTo,
			];
		}

		if (isset($data['tabs']) && is_array($data['tabs']))
		{
			$meta['tabs'] = $data['tabs'];
			unset($data['tabs']);
		}

		if (!empty($this->config->metaOverrides))
		{
			$meta = array_merge($meta, $this->config->metaOverrides);
		}

		$data['meta'] = $meta;
	}

	private function convertDataToColumnar(array &$chart): void
	{
		$rows = $chart['data'] ?? [];
		if (empty($rows) || !is_array($rows) || !is_array($rows[0]))
		{
			return;
		}

		$columns = [];
		foreach ($rows as $row)
		{
			if (!is_array($row))
			{
				continue;
			}
			foreach (array_keys($row) as $col)
			{
				if (!in_array($col, $columns, true))
				{
					$columns[] = $col;
				}
			}
		}
		$chart['columns'] = $columns;
		$chart['data'] = array_map(
			static fn(array $row) => array_map(
				static fn(string $col) => $row[$col] ?? null,
				$columns,
			),
			$rows,
		);
	}

	private function assemble(array &$data): void
	{
		$chartFields = $this->config->chartFields;

		foreach ($data['charts'] ?? [] as $i => $chart)
		{
			$this->convertDataToColumnar($chart);

			$ordered = [];
			foreach ($chartFields as $field)
			{
				if (array_key_exists($field, $chart))
				{
					$ordered[$field] = $chart[$field];
				}
			}
			$data['charts'][$i] = $ordered;
		}

		$topOrder = ['meta', 'applied_filters', 'dashboard', 'charts'];
		$ordered = [];
		foreach ($topOrder as $field)
		{
			if (array_key_exists($field, $data))
			{
				$ordered[$field] = $data[$field];
			}
		}
		foreach ($data as $key => $val)
		{
			if (!array_key_exists($key, $ordered))
			{
				$ordered[$key] = $val;
			}
		}
		$data = $ordered;

		unset($data['dashboard']['id']);
	}
}

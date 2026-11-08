<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Dashboard;

use Bitrix\AiAssistant\Exceptions\McpException;

/**
 * Builds Superset dashboard `position_json` payload from a high-level chart layout DSL.
 *
 * DSL form: a flat list of chart placements [{chartId, width: 1..12, height?, sliceName?}].
 * Charts are flowed into rows greedily: a chart goes into the current row if its
 * `width` still fits the row's remaining slots (out of 12 grid units); otherwise a new
 * row is started. Heights default to 50 grid units.
 *
 * The resulting structure follows Superset's dashboard component tree:
 *   ROOT_ID → GRID_ID → ROW-* → CHART-*
 */
final class DashboardLayoutBuilder
{
	private const GRID_WIDTH = 12;
	private const DEFAULT_CHART_HEIGHT = 50;
	private const MIN_CHART_WIDTH = 1;

	/**
	 * @param array $charts List of {chartId: int, width?: int 1..12, height?: int, sliceName?: string}.
	 * @param (\Closure(string): \Throwable)|null $refusal What a malformed chart is thrown as, given its
	 *     description; McpException by default.
	 * @return array position_json as a PHP array (will be JSON-encoded by the caller).
	 *
	 * @throws McpException on invalid input, unless another refusal is given.
	 */
	public static function build(array $charts, ?\Closure $refusal = null): array
	{
		$refusal ??= static fn(string $message): \Throwable => new McpException($message);

		$position = [
			'DASHBOARD_VERSION_KEY' => 'v2',
			'ROOT_ID' => [
				'type' => 'ROOT',
				'id' => 'ROOT_ID',
				'children' => ['GRID_ID'],
			],
			'GRID_ID' => [
				'type' => 'GRID',
				'id' => 'GRID_ID',
				'children' => [],
				'parents' => ['ROOT_ID'],
			],
			'HEADER_ID' => [
				'type' => 'HEADER',
				'id' => 'HEADER_ID',
				'meta' => ['text' => ''],
			],
		];

		if (empty($charts))
		{
			return $position;
		}

		$normalized = self::normalizeCharts($charts, $refusal);

		$rows = [];
		$currentRow = [];
		$currentWidth = 0;

		foreach ($normalized as $chart)
		{
			if ($currentWidth + $chart['width'] > self::GRID_WIDTH)
			{
				if (!empty($currentRow))
				{
					$rows[] = $currentRow;
				}
				$currentRow = [];
				$currentWidth = 0;
			}
			$currentRow[] = $chart;
			$currentWidth += $chart['width'];
		}

		if (!empty($currentRow))
		{
			$rows[] = $currentRow;
		}

		foreach ($rows as $rowIdx => $rowCharts)
		{
			$rowId = 'ROW-' . self::componentSuffix('R', $rowIdx);
			$position['GRID_ID']['children'][] = $rowId;

			$rowChartIds = [];
			foreach ($rowCharts as $chartIdx => $chart)
			{
				$chartComponentId = 'CHART-' . self::componentSuffix('C', $rowIdx, $chartIdx);
				$rowChartIds[] = $chartComponentId;

				$meta = [
					'chartId' => $chart['chartId'],
					'width' => $chart['width'],
					'height' => $chart['height'],
				];
				if ($chart['sliceName'] !== '')
				{
					$meta['sliceName'] = $chart['sliceName'];
				}

				$position[$chartComponentId] = [
					'type' => 'CHART',
					'id' => $chartComponentId,
					'children' => [],
					'meta' => $meta,
					'parents' => ['ROOT_ID', 'GRID_ID', $rowId],
				];
			}

			$position[$rowId] = [
				'type' => 'ROW',
				'id' => $rowId,
				'children' => $rowChartIds,
				'meta' => ['background' => 'BACKGROUND_TRANSPARENT'],
				'parents' => ['ROOT_ID', 'GRID_ID'],
			];
		}

		return $position;
	}

	/**
	 * @return array List of normalized charts: [{chartId, width, height, sliceName}].
	 */
	private static function normalizeCharts(array $charts, \Closure $refusal): array
	{
		$normalized = [];
		foreach ($charts as $idx => $chart)
		{
			if (!is_array($chart))
			{
				throw $refusal('charts[' . $idx . '] must be an object.');
			}

			$chartId = (int)($chart['chartId'] ?? 0);
			if ($chartId <= 0)
			{
				throw $refusal('charts[' . $idx . '].chartId must be a positive integer.');
			}

			$width = isset($chart['width']) ? (int)$chart['width'] : 6;
			if ($width < self::MIN_CHART_WIDTH || $width > self::GRID_WIDTH)
			{
				throw $refusal(
					'charts[' . $idx . '].width must be in range '
					. self::MIN_CHART_WIDTH . '..' . self::GRID_WIDTH . '.'
				);
			}

			$height = isset($chart['height']) ? (int)$chart['height'] : self::DEFAULT_CHART_HEIGHT;
			if ($height < 10 || $height > 200)
			{
				throw $refusal('charts[' . $idx . '].height must be in range 10..200.');
			}

			$sliceName = isset($chart['sliceName']) && is_string($chart['sliceName'])
				? trim($chart['sliceName'])
				: '';

			$normalized[] = [
				'chartId' => $chartId,
				'width' => $width,
				'height' => $height,
				'sliceName' => $sliceName,
			];
		}

		return $normalized;
	}

	private static function componentSuffix(string $prefix, int ...$indices): string
	{
		return $prefix . implode('-', array_map(static fn(int $i): string => (string)$i, $indices));
	}
}

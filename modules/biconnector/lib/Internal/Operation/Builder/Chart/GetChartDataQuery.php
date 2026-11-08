<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Chart;

/**
 * Arguments of GetChartDataOperation, named as get_chart_data names them and resolved by the entry point.
 */
final class GetChartDataQuery
{
	/**
	 * @param array $chartIds Charts of the dashboard to fetch, up to GetChartDataOperation::MAX_CHARTS_PER_CALL.
	 * @param array $appliedFilters Filters as get_chart_data takes them: `name` and `value` each.
	 * @param array $urlParams Values of the url parameters of a parameterized dashboard.
	 * @param int $rowLimit Rows per chart, clamped to 1..GetChartDataOperation::ROW_LIMIT_MAX.
	 * @param string|null $sortBy Column the rows are sorted by; null or blank keeps the server order.
	 * @param string $sortOrder `asc` or `desc`; anything else sorts descending.
	 */
	public function __construct(
		public readonly int $dashboardId,
		public readonly array $chartIds,
		public readonly array $appliedFilters = [],
		public readonly array $urlParams = [],
		public readonly int $rowLimit = GetChartDataOperation::ROW_LIMIT_DEFAULT,
		public readonly int $offset = 0,
		public readonly ?string $sortBy = null,
		public readonly string $sortOrder = 'desc',
	)
	{
	}
}

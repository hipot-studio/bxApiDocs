<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Chart;

use Bitrix\BIConnector\Internal\Operation\Builder\Chart\GetChartDataOperation;
use Bitrix\Main\Validation\Rule\ElementsType;
use Bitrix\Main\Validation\Rule\Enum\Type;
use Bitrix\Main\Validation\Rule\InArray;
use Bitrix\Main\Validation\Rule\Length;
use Bitrix\Main\Validation\Rule\Min;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Main\Validation\Rule\Range;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * Arguments of biconnector.builder.chart.data.get, named as get_chart_data names them.
 * Nothing is taken from a pinned context: a parameterized dashboard needs its urlParams explicitly.
 */
final class GetChartDataRequest extends Request
{
	/** Dashboard the charts sit on. */
	#[PositiveNumber]
	public int $dashboardId;

	/** Charts of the dashboard to fetch in one call, up to five, from biconnector.builder.dashboard.get. */
	#[ElementsType(typeEnum: Type::Integer)]
	public array $chartIds;

	/** Filters to apply, as in biconnector.builder.dashboard.get; leave `period` out for the dashboard default. */
	public array $appliedFilters = [];

	/** Values of the url parameters of a parameterized dashboard. */
	public array $urlParams = [];

	/** Rows per chart. */
	#[Range(1, GetChartDataOperation::ROW_LIMIT_MAX)]
	public int $rowLimit = GetChartDataOperation::ROW_LIMIT_DEFAULT;

	/** Rows to skip, for paging; advance by `rowLimit` while `has_more` is true. */
	#[Min(0)]
	public int $offset = 0;

	/** Column the rows are sorted by before the cut to `rowLimit`; blank keeps the server order. */
	#[Length(max: 255)]
	public string $sortBy = '';

	/** Direction of the sort by `sortBy`. */
	#[InArray(['asc', 'desc'], strict: true)]
	public string $sortOrder = 'desc';
}

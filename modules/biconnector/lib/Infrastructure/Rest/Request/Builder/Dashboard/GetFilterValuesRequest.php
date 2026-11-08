<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Dashboard;

use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\GetFilterValuesOperation;
use Bitrix\Main\Validation\Rule\Length;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Main\Validation\Rule\Range;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * Arguments of biconnector.builder.dashboard.filter.values, named as get_filter_values names them.
 * Nothing is taken from a pinned context: a parameterized dashboard needs its urlParams explicitly.
 */
final class GetFilterValuesRequest extends Request
{
	#[PositiveNumber]
	public int $dashboardId;

	/** Column of the filter, from `available_filters[].column` of the dashboard. */
	public string $filterColumn;

	/** Substring the values are narrowed by; blank means none. */
	#[Length(max: 200)]
	public string $search = '';

	/** Values to answer with. */
	#[Range(1, GetFilterValuesOperation::LIMIT_MAX)]
	public int $limit = GetFilterValuesOperation::LIMIT_DEFAULT;

	/** Filters that scope the values, as in biconnector.builder.dashboard.get. */
	public array $appliedFilters = [];

	/** Values of the url parameters of a parameterized dashboard. */
	public array $urlParams = [];
}

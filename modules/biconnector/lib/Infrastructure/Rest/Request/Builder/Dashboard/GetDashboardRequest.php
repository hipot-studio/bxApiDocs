<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Dashboard;

use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * Arguments of biconnector.builder.dashboard.get, named as get_dashboard_meta names them.
 * Nothing is taken from a pinned context: a parameterized dashboard needs its urlParams explicitly.
 */
final class GetDashboardRequest extends Request
{
	#[PositiveNumber]
	public int $dashboardId;

	/** Filters to apply, each with `name` and `value`; `period` takes {"from", "to"}. */
	public array $appliedFilters = [];

	/** Values of the url parameters of a parameterized dashboard. */
	public array $urlParams = [];
}

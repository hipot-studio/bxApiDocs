<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Dashboard;

use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * Arguments of biconnector.builder.dashboard.content.set, named as set_dashboard_content names them.
 * There is no confirmation step: a published dashboard is refused instead.
 */
final class SetDashboardContentRequest extends Request
{
	#[PositiveNumber]
	public int $dashboardId;

	/** Full chart layout, replaces the current one: {chartId, width?, height?, sliceName?} each. */
	public ?array $charts = null;

	/**
	 * Full filter configuration, replaces the current one: {name, filterType, datasetId?, column?, chartIds?,
	 * defaultValue?} each, with at least one `time_range` filter.
	 */
	public ?array $filters = null;
}

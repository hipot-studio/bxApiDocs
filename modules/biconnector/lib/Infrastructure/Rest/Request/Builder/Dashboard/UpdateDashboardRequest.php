<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Dashboard;

use Bitrix\Main\Validation\Rule\Length;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * Arguments of biconnector.builder.dashboard.update, named as update_dashboard_metadata names them.
 * There is no confirmation step: a published dashboard is refused instead.
 */
final class UpdateDashboardRequest extends Request
{
	#[PositiveNumber]
	public int $dashboardId;

	/** New title of the dashboard; blank is refused, and so is one longer than the column holds. */
	#[Length(min: 1, max: 128)]
	public string $name;
}

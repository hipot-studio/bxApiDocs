<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Dashboard;

use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * Arguments of biconnector.builder.dashboard.copy: the dashboard to copy.
 */
final class CopyDashboardRequest extends Request
{
	#[PositiveNumber]
	public int $dashboardId;
}

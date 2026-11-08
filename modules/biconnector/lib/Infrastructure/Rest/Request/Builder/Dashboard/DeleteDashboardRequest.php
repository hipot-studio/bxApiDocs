<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Dashboard;

use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * Arguments of biconnector.builder.dashboard.delete, named as delete_dashboard names them. There is no
 * `confirm`: a REST call is the confirmed deletion.
 */
final class DeleteDashboardRequest extends Request
{
	#[PositiveNumber]
	public int $dashboardId;
}

<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Dashboard;

use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Interaction\Request\Request;

final class GetDashboardLayoutRequest extends Request
{
	#[PositiveNumber]
	public int $dashboardId;
}

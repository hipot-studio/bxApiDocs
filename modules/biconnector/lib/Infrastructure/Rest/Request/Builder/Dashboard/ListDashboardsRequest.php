<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Dashboard;

use Bitrix\Main\Validation\Rule\Min;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * Arguments of biconnector.builder.dashboard.list, named as list_dashboards names them.
 */
final class ListDashboardsRequest extends Request
{
	/** Substring of the title or the description; blank lists every dashboard. */
	public string $query = '';

	/** Dashboards to skip, for paging; the page holds up to 50. */
	#[Min(0)]
	public int $offset = 0;
}

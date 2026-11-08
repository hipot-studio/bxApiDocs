<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Dashboard;

use Bitrix\Main\Validation\Rule\Length;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * Arguments of biconnector.builder.dashboard.parameters.search, named as search_dashboard_parameters
 * names them.
 */
final class SearchDashboardParametersRequest extends Request
{
	#[PositiveNumber]
	public int $dashboardId;

	/** Substring the values of each parameter are narrowed by; blank means none. */
	#[Length(max: 200)]
	public string $search = '';

	/** Zero-based offset of the page of values. */
	public int $offset = 0;
}

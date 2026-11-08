<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Dashboard;

use Bitrix\Main\Validation\Rule\Length;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * Arguments of biconnector.builder.dashboard.add, named as create_dashboard names them.
 */
final class CreateDashboardRequest extends Request
{
	/** Title of the dashboard; blank is refused, and so is one longer than the column holds. */
	#[Length(min: 1, max: 128)]
	public string $name;
}

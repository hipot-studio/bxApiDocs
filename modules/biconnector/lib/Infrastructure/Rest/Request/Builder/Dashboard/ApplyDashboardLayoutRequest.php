<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Dashboard;

use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Interaction\Request\Request;

final class ApplyDashboardLayoutRequest extends Request
{
	#[PositiveNumber]
	public int $dashboardId;

	/** A list of 1..50 operations, validated by the shared LayoutBatch and OperationSchema. */
	public array $operations;

	public ?string $version = null;
	public bool $dryRun = false;
}

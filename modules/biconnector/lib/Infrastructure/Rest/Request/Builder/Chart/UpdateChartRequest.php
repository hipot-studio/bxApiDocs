<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Chart;

use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * Arguments of biconnector.builder.chart.update, named as update_chart_metadata names them.
 * There is no confirmation step.
 */
final class UpdateChartRequest extends Request
{
	/** Chart id from biconnector.builder.chart.list. */
	#[PositiveNumber]
	public int $chartId;

	/** New chart display name; omitted keeps the current one. */
	public ?string $name = null;

	/** New chart description; an empty string clears it, omitted keeps the current one. */
	public ?string $description = null;
}

<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Chart;

use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * Arguments of biconnector.builder.chart.delete, named as delete_chart names them.
 * There is no confirmation step.
 */
final class DeleteChartRequest extends Request
{
	/** Chart id from biconnector.builder.chart.list. */
	#[PositiveNumber]
	public int $chartId;
}

<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Chart;

use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * Arguments of biconnector.builder.chart.get, named as get_chart names them.
 */
final class GetChartRequest extends Request
{
	/** Chart id from biconnector.builder.chart.list. */
	#[PositiveNumber]
	public int $chartId;
}

<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Chart;

use Bitrix\BIConnector\Internal\Operation\Builder\Chart\ListChartsOperation;
use Bitrix\Main\Validation\Rule\Min;
use Bitrix\Main\Validation\Rule\Range;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * Arguments of biconnector.builder.chart.list, named as list_charts names them.
 */
final class ListChartsRequest extends Request
{
	/** Page size. */
	#[Range(1, ListChartsOperation::LIMIT_MAX)]
	public int $limit = ListChartsOperation::LIMIT_DEFAULT;

	/** Charts to skip, rounded down to a multiple of `limit`; the answer echoes the effective offset. */
	#[Min(0)]
	public int $offset = 0;
}

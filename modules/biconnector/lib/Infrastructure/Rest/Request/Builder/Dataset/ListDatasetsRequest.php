<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Dataset;

use Bitrix\BIConnector\Internal\Operation\Builder\Dataset\ListDatasetsOperation;
use Bitrix\Main\Validation\Rule\Min;
use Bitrix\Main\Validation\Rule\Range;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * Arguments of biconnector.builder.dataset.list, named as list_datasets names them.
 */
final class ListDatasetsRequest extends Request
{
	/** Page size. */
	#[Range(1, ListDatasetsOperation::LIMIT_MAX)]
	public int $limit = ListDatasetsOperation::LIMIT_DEFAULT;

	/** Datasets to skip, rounded down to a multiple of `limit`; the answer echoes the effective offset. */
	#[Min(0)]
	public int $offset = 0;
}

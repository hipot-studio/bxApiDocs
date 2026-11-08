<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Dataset;

use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * Arguments of biconnector.builder.dataset.get, named as get_dataset names them.
 */
final class GetDatasetRequest extends Request
{
	/** Dataset id from biconnector.builder.dataset.list. */
	#[PositiveNumber]
	public int $datasetId;
}

<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Dataset;

use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * Arguments of biconnector.builder.dataset.delete, named as delete_dataset names them. There is no
 * `confirm`: a REST call is the confirmed deletion.
 */
final class DeleteDatasetRequest extends Request
{
	#[PositiveNumber]
	public int $datasetId;
}

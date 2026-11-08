<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Dataset;

use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * Arguments of biconnector.builder.dataset.update, named as update_dataset names them. There is no
 * `confirm`: a REST call is the confirmed update.
 */
final class UpdateDatasetRequest extends Request
{
	#[PositiveNumber]
	public int $datasetId;

	/**
	 * Full replacement list of the projected columns, each with `name` and `type`
	 * (INT, STRING, DOUBLE, DATE or DATETIME): a column left out is dropped.
	 */
	public array $columns;
}

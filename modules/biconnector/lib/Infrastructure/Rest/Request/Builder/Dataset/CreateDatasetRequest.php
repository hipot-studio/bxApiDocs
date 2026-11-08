<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Dataset;

use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * Arguments of biconnector.builder.dataset.add, named as create_dataset_from_sql names them.
 */
final class CreateDatasetRequest extends Request
{
	/** Display name of the new dataset, unique on the portal. */
	public string $datasetName;

	/** A single SELECT valid for the BI database; tables are referenced as "bitrix24"."TABLE_NAME". */
	public string $sql;
}

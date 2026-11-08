<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Dataset;

use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * Arguments of biconnector.builder.dataset.addfromtable, named as create_virtual_dataset names them.
 */
final class CreateDatasetFromTableRequest extends Request
{
	/** Display name of the new dataset, unique on the portal. */
	public string $datasetName;

	/** BI table the dataset projects, as biconnector.builder.table.list names it. */
	public string $sourceTable;

	/** Names of the columns to project, as the schema of the table names them. */
	public array $columns;

	/** Schema of the table; blank takes the default BI schema. */
	public string $sourceSchema = '';
}

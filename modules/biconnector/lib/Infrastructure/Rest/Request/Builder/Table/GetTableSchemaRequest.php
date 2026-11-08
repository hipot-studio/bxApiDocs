<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Table;

use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * Arguments of biconnector.builder.table.schema.get, named as get_bi_table_schema names them.
 */
final class GetTableSchemaRequest extends Request
{
	/** Physical table name from biconnector.builder.table.list. */
	public string $tableName;

	/** Schema of the table; blank means the default BI schema. */
	public string $schema = '';
}

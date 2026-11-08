<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Table;

use Bitrix\BIConnector\Internal\Operation\Builder\Table\ListBiTablesOperation;
use Bitrix\Main\Validation\Rule\Min;
use Bitrix\Main\Validation\Rule\Range;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * Arguments of biconnector.builder.table.list, named as list_bi_tables names them.
 */
final class ListBiTablesRequest extends Request
{
	/** Schema of the tables; blank means the default BI schema. */
	public string $schema = '';

	/** Page size. */
	#[Range(1, ListBiTablesOperation::LIMIT_MAX)]
	public int $limit = ListBiTablesOperation::LIMIT_DEFAULT;

	/** Tables to skip. */
	#[Min(0)]
	public int $offset = 0;
}

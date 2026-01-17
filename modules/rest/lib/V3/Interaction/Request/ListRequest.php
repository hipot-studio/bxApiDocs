<?php

namespace Bitrix\Rest\V3\Interaction\Request;

use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\SelectStructure;
use Bitrix\Rest\V3\Structure\PaginationStructure;

class ListRequest extends Request
{
	public ?SelectStructure $select = null;

	public ?FilterStructure $filter = null;

	public ?OrderStructure $order = null;

	public ?PaginationStructure $pagination = null;
}

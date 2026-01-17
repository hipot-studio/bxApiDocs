<?php

namespace Bitrix\Rest\V3\Interaction\Request;

use Bitrix\Rest\V3\Structure\CursorStructure;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\SelectStructure;

class TailRequest extends Request
{
	public ?SelectStructure $select = null;

	public ?FilterStructure $filter = null;

	public ?CursorStructure $cursor = null;
}

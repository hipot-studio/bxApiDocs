<?php

namespace Bitrix\Rest\V3\Interaction\Request;

use Bitrix\Rest\V3\Structure\SelectStructure;

class GetRequest extends Request
{
	public int $id;

	public ?SelectStructure $select = null;
}

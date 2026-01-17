<?php

namespace Bitrix\Rest\V3\Interaction\Request;

use Bitrix\Rest\V3\Structure\FieldsStructure;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;

class UpdateRequest extends Request
{
	public ?int $id = null;

	public FieldsStructure $fields;

	public ?FilterStructure $filter = null;
}

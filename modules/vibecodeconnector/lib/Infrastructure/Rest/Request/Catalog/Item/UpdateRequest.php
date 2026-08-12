<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Rest\Request\Catalog\Item;

use Bitrix\Main\Validation\Rule;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\FieldsStructure;

class UpdateRequest extends Request
{
	#[Rule\PositiveNumber]
	public int $catalogItemId;

	public FieldsStructure $fields;
}

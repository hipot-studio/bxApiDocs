<?php

namespace Bitrix\Rest\V3\Interaction\Request;

use Bitrix\Main\Validation\Rule\AtLeastOnePropertyNotEmpty;
use Bitrix\Main\Validation\Rule\OnlyOneOfPropertyRequired;
use Bitrix\Rest\V3\Structure\FieldsStructure;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;

#[AtLeastOnePropertyNotEmpty(propertyNames: ['id', 'filter'], showPropertyNames: true)]
#[OnlyOneOfPropertyRequired(propertyNames: ['id', 'filter'])]
class UpdateRequest extends Request
{
	public ?string $id = null;

	public FieldsStructure $fields;

	public ?FilterStructure $filter = null;
}

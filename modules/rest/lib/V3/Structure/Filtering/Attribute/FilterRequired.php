<?php

namespace Bitrix\Rest\V3\Structure\Filtering\Attribute;

use Bitrix\Rest\V3\Attribute\AbstractAttribute;

#[\Attribute]
class FilterRequired extends AbstractAttribute
{
	public function __construct(public readonly array $fields)
	{
	}
}
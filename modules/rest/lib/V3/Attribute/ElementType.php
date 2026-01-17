<?php

namespace Bitrix\Rest\V3\Attribute;

#[\Attribute]
class ElementType extends AbstractAttribute
{
	public function __construct(public readonly string $type)
	{
	}
}

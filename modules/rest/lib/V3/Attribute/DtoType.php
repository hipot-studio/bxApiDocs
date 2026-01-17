<?php

namespace Bitrix\Rest\V3\Attribute;

#[\Attribute]
class DtoType extends AbstractAttribute
{
	public function __construct(public readonly string $type)
	{
	}
}

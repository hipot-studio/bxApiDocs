<?php

namespace Bitrix\Rest\V3\Attribute;

#[\Attribute]
class Scope extends AbstractAttribute
{
	public function __construct(public readonly string $value)
	{
	}
}

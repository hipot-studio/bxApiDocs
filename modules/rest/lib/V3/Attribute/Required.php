<?php

namespace Bitrix\Rest\V3\Attribute;

#[\Attribute]
class Required extends AbstractAttribute
{
	public function __construct(public readonly array $groups = [])
	{
	}
}

<?php

namespace Bitrix\Rest\V3\Attribute;

#[\Attribute]
class Editable extends AbstractAttribute
{
	public function __construct(public readonly array $groups = [])
	{
	}
}

<?php

namespace Bitrix\Rest\V3\Dto\Attribute;
use Bitrix\Rest\V3\Attribute\AbstractAttribute;

#[\Attribute]
class TypeAlias extends AbstractAttribute
{
	public function __construct(public readonly string $alias)
	{
	}
}

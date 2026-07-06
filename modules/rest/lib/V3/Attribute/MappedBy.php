<?php

namespace Bitrix\Rest\V3\Attribute;

use Bitrix\Rest\V3\Dto\Mapping\Mapper;
use Bitrix\Rest\V3\Exception\InvalidClassInstanceProvidedException;

#[\Attribute(\Attribute::TARGET_CLASS)]
class MappedBy extends AbstractAttribute
{
	public function __construct(public readonly string $mapperClass)
	{
		if (!class_exists($this->mapperClass) || !is_subclass_of($this->mapperClass, Mapper::class))
		{
			throw new InvalidClassInstanceProvidedException($this->mapperClass, Mapper::class);
		}
	}
}

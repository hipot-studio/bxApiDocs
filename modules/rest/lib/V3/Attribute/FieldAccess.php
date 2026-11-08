<?php

namespace Bitrix\Rest\V3\Attribute;

use Bitrix\Rest\V3\Access\FieldAccessProvider;
use Bitrix\Rest\V3\Exception\InvalidClassInstanceProvidedException;

/**
 * Declares the access provider of the field methods on a resource DTO:
 * it covers every field method generated for that resource.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class FieldAccess extends AbstractAttribute
{
	public function __construct(public readonly string $provider)
	{
		if (!class_exists($this->provider))
		{
			throw new \InvalidArgumentException("Provider class '{$this->provider}' does not exist");
		}

		if (!is_subclass_of($this->provider, FieldAccessProvider::class))
		{
			throw new InvalidClassInstanceProvidedException($this->provider, FieldAccessProvider::class);
		}
	}
}

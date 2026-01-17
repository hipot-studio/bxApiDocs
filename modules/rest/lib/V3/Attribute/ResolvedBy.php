<?php

namespace Bitrix\Rest\V3\Attribute;

use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\InvalidClassInstanceProvidedException;

#[\Attribute]
class ResolvedBy extends AbstractAttribute
{
	public function __construct(public readonly string $controller)
	{
		if (!is_subclass_of($this->controller, RestController::class))
		{
			throw new InvalidClassInstanceProvidedException($this->controller, RestController::class);
		}
	}
}

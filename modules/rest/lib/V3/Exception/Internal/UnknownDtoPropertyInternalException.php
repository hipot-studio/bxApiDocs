<?php

namespace Bitrix\Rest\V3\Exception\Internal;

class UnknownDtoPropertyInternalException extends InternalException
{
	public function __construct(string $propertyName, string $dtoClass)
	{
		$this->message = "Property `{$propertyName}` not found in `{$dtoClass}`";

		parent::__construct($this);
	}
}

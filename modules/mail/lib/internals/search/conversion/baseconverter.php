<?php

namespace Bitrix\Mail\Internals\Search\Conversion;

class BaseConverter implements ConverterInterface
{
	public function convert(mixed $data): ?string
	{
		return $data;
	}
}

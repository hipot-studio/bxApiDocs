<?php

namespace Bitrix\Mail\Internals\Search\Conversion;

interface ConverterInterface
{
	public function convert(mixed $data): ?string;
}

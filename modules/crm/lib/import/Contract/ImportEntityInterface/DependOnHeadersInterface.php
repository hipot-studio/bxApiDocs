<?php

namespace Bitrix\Crm\Import\Contract\ImportEntityInterface;

interface DependOnHeadersInterface
{
	public function setHeaders(array $headers): self;
}

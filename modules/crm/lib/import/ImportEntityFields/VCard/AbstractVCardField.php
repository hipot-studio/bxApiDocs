<?php

namespace Bitrix\Crm\Import\ImportEntityFields\VCard;

use Bitrix\Crm\Import\Contract\ImportEntityFieldInterface;

abstract class AbstractVCardField implements ImportEntityFieldInterface
{
	final public function isRequired(): bool
	{
		return false;
	}

	final public function isReadonly(): bool
	{
		return true;
	}
}

<?php

namespace Bitrix\Crm\Import\Contract\ImportEntityFieldInterface;

interface CanConfigureFieldBindingMap
{
	public function isFieldBindingMapEnabled(): bool;
}

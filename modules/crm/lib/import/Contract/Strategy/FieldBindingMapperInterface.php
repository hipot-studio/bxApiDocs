<?php

namespace Bitrix\Crm\Import\Contract\Strategy;

use Bitrix\Crm\Import\Contract\File\ReaderInterface;
use Bitrix\Crm\Import\Dto\Entity\FieldBindings;

interface FieldBindingMapperInterface
{
	public function map(ReaderInterface $reader): FieldBindings;
}

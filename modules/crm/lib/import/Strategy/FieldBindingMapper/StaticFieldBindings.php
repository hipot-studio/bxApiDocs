<?php

namespace Bitrix\Crm\Import\Strategy\FieldBindingMapper;

use Bitrix\Crm\Import\Contract\File\ReaderInterface;
use Bitrix\Crm\Import\Contract\Strategy\FieldBindingMapperInterface;
use Bitrix\Crm\Import\Dto\Entity\FieldBindings;

final class StaticFieldBindings implements FieldBindingMapperInterface
{
	public function __construct(
		private readonly FieldBindings $fieldBindings,
	)
	{
	}

	public function map(ReaderInterface $reader): FieldBindings
	{
		return $this->fieldBindings;
	}
}

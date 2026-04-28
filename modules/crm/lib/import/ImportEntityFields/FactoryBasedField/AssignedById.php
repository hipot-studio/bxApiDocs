<?php

namespace Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\ImportEntityFields\Trait\CanConfigureNameFormatTrait;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Import\Strategy\ValueMapper\UserIdValueMapper;
use Bitrix\Crm\Item;

final class AssignedById extends AbstractFactoryBasedField
{
	use CanConfigureNameFormatTrait;

	public function getId(): string
	{
		return Item::FIELD_NAME_ASSIGNED;
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		return (new UserIdValueMapper($this->getId(), $this->nameFormat))
			->process($importItemFields, $fieldBindings, $row)
		;
	}
}

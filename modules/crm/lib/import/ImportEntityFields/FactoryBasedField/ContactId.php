<?php

namespace Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Import\Strategy\ValueMapper\FactoryItemValueMapper;
use Bitrix\Crm\Item;
use CCrmOwnerType;

final class ContactId extends AbstractFactoryBasedField
{
	public function getId(): string
	{
		return Item::FIELD_NAME_CONTACT_ID;
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		return (new FactoryItemValueMapper($this->getId(), CCrmOwnerType::Contact))
			->process($importItemFields, $fieldBindings, $row)
		;
	}
}

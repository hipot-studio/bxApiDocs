<?php

namespace Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Import\Strategy\ValueMapper\FactoryItemValueMapper;
use Bitrix\Crm\Item;
use CCrmOwnerType;

final class CompanyId extends AbstractFactoryBasedField
{
	public const ID = Item::FIELD_NAME_COMPANY_ID;

	public function getId(): string
	{
		return self::ID;
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		return (new FactoryItemValueMapper($this->getId(), CCrmOwnerType::Company))
			->process($importItemFields, $fieldBindings, $row)
		;
	}
}

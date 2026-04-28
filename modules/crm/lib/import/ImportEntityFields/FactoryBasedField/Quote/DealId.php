<?php

namespace Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Quote;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\AbstractFactoryBasedField;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Import\Strategy\ValueMapper\FactoryItemValueMapper;
use Bitrix\Crm\Item\Quote;

final class DealId extends AbstractFactoryBasedField
{
	public function getId(): string
	{
		return Quote::FIELD_NAME_DEAL_ID;
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		return (new FactoryItemValueMapper($this->getId(), \CCrmOwnerType::Deal))
			->process($importItemFields, $fieldBindings, $row)
		;
	}
}

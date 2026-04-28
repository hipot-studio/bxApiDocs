<?php

namespace Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Import\Strategy\ValueMapper\FloatValueMapper;
use Bitrix\Crm\Item;

final class Opportunity extends AbstractFactoryBasedField
{
	public function getId(): string
	{
		return Item::FIELD_NAME_OPPORTUNITY;
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		return (new FloatValueMapper($this->getId()))
			->process($importItemFields, $fieldBindings, $row)
		;
	}
}

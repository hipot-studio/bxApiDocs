<?php

namespace Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Import\Strategy\ValueMapper\IntegerValueMapper;
use Bitrix\Crm\Item;

final class Id extends AbstractFactoryBasedField
{
	public function getId(): string
	{
		return Item::FIELD_NAME_ID;
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		return (new IntegerValueMapper($this->getId()))
			->process($importItemFields, $fieldBindings, $row)
		;
	}
}

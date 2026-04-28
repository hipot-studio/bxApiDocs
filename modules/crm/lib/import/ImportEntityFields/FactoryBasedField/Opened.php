<?php

namespace Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Import\Strategy\ValueMapper\BoolValueMapper;
use Bitrix\Crm\Item;

final class Opened extends AbstractFactoryBasedField
{
	public function getId(): string
	{
		return Item::FIELD_NAME_OPENED;
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		return (new BoolValueMapper($this->getId()))
			->process($importItemFields, $fieldBindings, $row)
		;
	}
}

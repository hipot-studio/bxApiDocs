<?php

namespace Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Deal;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\AbstractFactoryBasedField;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Import\Strategy\ValueMapper\IntegerValueMapper;
use Bitrix\Crm\Item;

final class Probability extends AbstractFactoryBasedField
{
	public function getId(): string
	{
		return Item\Deal::FIELD_NAME_PROBABILITY;
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		return (new IntegerValueMapper($this->getId(), min: 0, max: 100))
			->process($importItemFields, $fieldBindings, $row)
		;
	}
}

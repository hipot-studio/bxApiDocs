<?php

namespace Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Quote;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\AbstractFactoryBasedField;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Import\Strategy\ValueMapper\StringValueMapper;
use Bitrix\Crm\Item\Quote;

final class ActualDate extends AbstractFactoryBasedField
{
	public function getId(): string
	{
		return Quote::FIELD_NAME_ACTUAL_DATE;
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		return (new StringValueMapper($this->getId()))
			->process($importItemFields, $fieldBindings, $row)
		;
	}
}

<?php

namespace Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Import\Strategy\ValueMapper\EnumValueMapper;
use Bitrix\Crm\Item;
use CCrmStatus;

final class SourceId extends AbstractFactoryBasedField
{
	public function getId(): string
	{
		return Item::FIELD_NAME_SOURCE_ID;
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		$items = CCrmStatus::GetStatusListEx('SOURCE');

		return (new EnumValueMapper($this->getId(), $items))
			->process($importItemFields, $fieldBindings, $row)
		;
	}
}

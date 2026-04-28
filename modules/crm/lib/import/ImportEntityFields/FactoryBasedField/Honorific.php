<?php

namespace Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Import\Strategy\ValueMapper\EnumValueMapper;
use Bitrix\Crm\Item;
use CCrmStatus;

final class Honorific extends AbstractFactoryBasedField
{
	public const ID = Item::FIELD_NAME_HONORIFIC;

	public function getId(): string
	{
		return self::ID;
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		$items = CCrmStatus::GetStatusListEx('HONORIFIC');

		return (new EnumValueMapper($this->getId(), $items))
			->process($importItemFields, $fieldBindings, $row)
		;
	}
}

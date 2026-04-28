<?php

namespace Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Import\Strategy\ValueMapper\EnumValueMapper;
use Bitrix\Crm\Item;
use Bitrix\Crm\StatusTable;
use CCrmOwnerType;
use CCrmStatus;

final class TypeId extends AbstractFactoryBasedField
{
	public function getId(): string
	{
		return Item::FIELD_NAME_TYPE_ID;
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		$type = match ($this->entityTypeId) {
			CCrmOwnerType::Deal => StatusTable::ENTITY_ID_DEAL_TYPE,
			CCrmOwnerType::Contact => StatusTable::ENTITY_ID_CONTACT_TYPE,
			CCrmOwnerType::Company => StatusTable::ENTITY_ID_COMPANY_TYPE,
			default => null,
		};

		if ($type === null)
		{
			return FieldProcessResult::success();
		}

		$items = CCrmStatus::GetStatusList($type);

		return (new EnumValueMapper($this->getId(), $items))
			->process($importItemFields, $fieldBindings, $row)
		;
	}
}

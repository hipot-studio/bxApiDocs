<?php

namespace Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Company;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\AbstractFactoryBasedField;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Import\Strategy\ValueMapper\EnumValueMapper;
use Bitrix\Crm\Item\Company;
use Bitrix\Crm\StatusTable;
use CCrmStatus;

final class Employees extends AbstractFactoryBasedField
{
	public function getId(): string
	{
		return Company::FIELD_NAME_EMPLOYEES;
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		$items = CCrmStatus::GetStatusList(StatusTable::ENTITY_ID_EMPLOYEES);

		return (new EnumValueMapper($this->getId(), $items))
			->process($importItemFields, $fieldBindings, $row)
		;
	}
}

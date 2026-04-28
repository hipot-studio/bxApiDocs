<?php

namespace Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Company;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\AbstractFactoryBasedField;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Import\Strategy\ValueMapper\BoolValueMapper;
use Bitrix\Crm\Item\Company;

final class IsMyCompany extends AbstractFactoryBasedField
{
	public function getId(): string
	{
		return Company::FIELD_NAME_IS_MY_COMPANY;
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		return (new BoolValueMapper($this->getId()))
			->process($importItemFields, $fieldBindings, $row)
		;
	}
}

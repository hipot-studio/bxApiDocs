<?php

namespace Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Company;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\AbstractFactoryBasedField;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Import\Strategy\ValueMapper\StringValueMapper;
use Bitrix\Crm\Item\Company;

class BankingDetails extends AbstractFactoryBasedField
{
	public function getId(): string
	{
		return Company::FIELD_NAME_BANKING_DETAILS;
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		return (new StringValueMapper($this->getId()))
			->process($importItemFields, $fieldBindings, $row)
		;
	}
}

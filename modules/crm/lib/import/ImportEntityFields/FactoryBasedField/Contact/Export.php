<?php

namespace Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Contact;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\AbstractFactoryBasedField;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Import\Strategy\ValueMapper\BoolValueMapper;
use Bitrix\Crm\Item\Contact;

final class Export extends AbstractFactoryBasedField
{
	public function getId(): string
	{
		return Contact::FIELD_NAME_EXPORT;
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		return (new BoolValueMapper($this->getId()))
			->process($importItemFields, $fieldBindings, $row)
		;
	}
}

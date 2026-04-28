<?php

namespace Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Lead;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\AbstractFactoryBasedField;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Import\Strategy\ValueMapper\StringValueMapper;
use Bitrix\Crm\Item\Lead;

final class StageDescription extends AbstractFactoryBasedField
{
	public function getId(): string
	{
		return Lead::FIELD_NAME_STATUS_DESCRIPTION;
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		return (new StringValueMapper($this->getId()))
			->process($importItemFields, $fieldBindings, $row)
		;
	}
}

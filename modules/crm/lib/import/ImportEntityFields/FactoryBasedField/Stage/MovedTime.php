<?php

namespace Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Stage;

use Bitrix\Crm\Import\Contract\ImportEntityFieldInterface\CanConfigureFieldBindingMap;
use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\AbstractFactoryBasedField;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Import\Strategy\ValueMapper\StringValueMapper;
use Bitrix\Crm\Item;

final class MovedTime extends AbstractFactoryBasedField implements CanConfigureFieldBindingMap
{
	public function isFieldBindingMapEnabled(): bool
	{
		return false;
	}

	public function getId(): string
	{
		return Item::FIELD_NAME_MOVED_TIME;
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		return (new StringValueMapper($this->getId()))
			->process($importItemFields, $fieldBindings, $row)
		;
	}
}

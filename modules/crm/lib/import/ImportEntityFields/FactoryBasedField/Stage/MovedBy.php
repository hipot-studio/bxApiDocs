<?php

namespace Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Stage;

use Bitrix\Crm\Import\Contract\ImportEntityFieldInterface\CanConfigureFieldBindingMap;
use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\AbstractFactoryBasedField;
use Bitrix\Crm\Import\ImportEntityFields\Trait\CanConfigureNameFormatTrait;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Import\Strategy\ValueMapper\UserIdValueMapper;
use Bitrix\Crm\Item;

final class MovedBy extends AbstractFactoryBasedField implements CanConfigureFieldBindingMap
{
	use CanConfigureNameFormatTrait;

	public function isFieldBindingMapEnabled(): bool
	{
		return false;
	}

	public function getId(): string
	{
		return Item::FIELD_NAME_MOVED_BY;
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		return (new UserIdValueMapper($this->getId(), $this->nameFormat))
			->process($importItemFields, $fieldBindings, $row)
		;
	}
}

<?php

namespace Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Import\Strategy\ValueMapper\StringValueMapper;
use Bitrix\Crm\Item;

final class Post extends AbstractFactoryBasedField
{
	public const ID = Item::FIELD_NAME_POST;

	public function getId(): string
	{
		return self::ID;
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		return (new StringValueMapper($this->getId()))
			->process($importItemFields, $fieldBindings, $row)
		;
	}
}

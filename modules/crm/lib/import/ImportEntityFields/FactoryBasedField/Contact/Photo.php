<?php

namespace Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Contact;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\AbstractFactoryBasedField;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Import\Strategy\ValueMapper\FileValueMapper;
use Bitrix\Crm\Item\Contact;

final class Photo extends AbstractFactoryBasedField
{
	public const ID = Contact::FIELD_NAME_PHOTO;

	public function getId(): string
	{
		return self::ID;
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		return (new FileValueMapper($this->getId()))
			->process($importItemFields, $fieldBindings, $row)
		;
	}
}

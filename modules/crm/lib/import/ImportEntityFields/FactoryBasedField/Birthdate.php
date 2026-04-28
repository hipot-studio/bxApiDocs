<?php

namespace Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Item;
use Bitrix\Crm\Item\Contact;
use Bitrix\Main\Type\DateTime;

final class Birthdate extends AbstractFactoryBasedField
{
	public const ID = Item::FIELD_NAME_BIRTHDATE;

	public function getId(): string
	{
		return self::ID;
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		$columnIndex = $fieldBindings->getColumnIndexByFieldId($this->getId());
		if ($columnIndex === null)
		{
			return FieldProcessResult::skip();
		}

		$value = $row[$columnIndex] ?? null;
		if (empty($value))
		{
			return FieldProcessResult::skip();
		}

		$time = strtotime($value);
		if ($time === false)
		{
			return FieldProcessResult::skip();
		}

		$importItemFields[Contact::FIELD_NAME_BIRTHDATE] = DateTime::createFromTimestamp($time)->toString();

		return FieldProcessResult::success();
	}
}

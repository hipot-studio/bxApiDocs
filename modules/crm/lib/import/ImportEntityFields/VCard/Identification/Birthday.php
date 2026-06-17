<?php

namespace Bitrix\Crm\Import\ImportEntityFields\VCard\Identification;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\ImportEntityFields\VCard\AbstractVCardField;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Item\Contact;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\VCard\VCardLine;
use CCrmOwnerType;

final class Birthday extends AbstractVCardField
{
	public const ID = 'BIRTHDAY';

	public function getId(): string
	{
		return self::ID;
	}

	public function getCaption(): string
	{
		return Container::getInstance()
			->getFactory(CCrmOwnerType::Contact)
			?->getFieldCaption(Contact::FIELD_NAME_BIRTHDATE);
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		$columnIndex = $fieldBindings->getColumnIndexByFieldId(self::ID);
		if ($columnIndex === null)
		{
			return FieldProcessResult::skip();
		}

		$vcardLineParts = $row[$columnIndex][0] ?? null;
		if (empty($vcardLineParts))
		{
			return FieldProcessResult::skip();
		}

		$vcardLine = new VCardLine($vcardLineParts);
		if (!$vcardLine->validate()->isSuccess())
		{
			return FieldProcessResult::skip();
		}

		$value = $vcardLine->getValue();
		if (empty($value) || !is_string($value))
		{
			return FieldProcessResult::skip();
		}

		$importItemFields[Contact::FIELD_NAME_BIRTHDATE] = $value;

		return FieldProcessResult::success();
	}
}

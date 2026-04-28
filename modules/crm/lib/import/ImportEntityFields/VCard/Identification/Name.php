<?php

namespace Bitrix\Crm\Import\ImportEntityFields\VCard\Identification;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\ImportEntityFields\VCard\AbstractVCardField;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Item\Contact;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\VCard\VCardLine;
use CCrmOwnerType;
use CCrmStatus;

final class Name extends AbstractVCardField
{
	public const ID = 'NAME';

	public function getId(): string
	{
		return self::ID;
	}

	public function getCaption(): string
	{
		return Container::getInstance()
			->getFactory(CCrmOwnerType::Contact)
			?->getFieldCaption(Contact::FIELD_NAME_FULL_NAME);
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

		[
			$lastName,
			$name,
			$secondName,
			$prefix,
			$suffix,
		] = explode(';', $vcardLine->getValue());

		$importItemFields[Contact::FIELD_NAME_LAST_NAME] = $lastName;
		$importItemFields[Contact::FIELD_NAME_NAME] = $name;
		$importItemFields[Contact::FIELD_NAME_SECOND_NAME] = $secondName;

		$honorifics = CCrmStatus::GetStatusListEx('HONORIFIC');
		$honorificSearch = array_search($prefix, $honorifics, true);
		if ($honorificSearch !== false)
		{
			$importItemFields[Contact::FIELD_NAME_HONORIFIC] = $honorificSearch;
		}

		return FieldProcessResult::success();
	}
}

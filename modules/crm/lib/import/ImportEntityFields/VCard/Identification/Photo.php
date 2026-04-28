<?php

namespace Bitrix\Crm\Import\ImportEntityFields\VCard\Identification;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\ImportEntityFields\VCard\AbstractVCardField;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Import\Strategy\ValueMapper\VCard\FileValueMapper;
use Bitrix\Crm\Item\Contact;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\VCard\VCardLine;
use CCrmOwnerType;

final class Photo extends AbstractVCardField
{
	public const ID = 'PHOTO';

	public function getId(): string
	{
		return self::ID;
	}

	public function getCaption(): string
	{
		return Container::getInstance()
			->getFactory(CCrmOwnerType::Contact)
			?->getFieldCaption(Contact::FIELD_NAME_PHOTO);
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

		$file = (new FileValueMapper())->process($vcardLine);
		if ($file === null)
		{
			return FieldProcessResult::skip();
		}

		$importItemFields[Contact::FIELD_NAME_PHOTO] = $file;

		return FieldProcessResult::success();
	}
}

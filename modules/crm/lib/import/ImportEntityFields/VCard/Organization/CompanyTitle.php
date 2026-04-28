<?php

namespace Bitrix\Crm\Import\ImportEntityFields\VCard\Organization;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\ImportEntityFields\VCard\AbstractVCardField;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Item\Company;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\VCard\VCardLine;
use CCrmOwnerType;

final class CompanyTitle extends AbstractVCardField
{
	public const ID = 'COMPANY_TITLE';

	public function getId(): string
	{
		return self::ID;
	}

	public function getCaption(): string
	{
		return Container::getInstance()
			->getFactory(CCrmOwnerType::Contact)
			?->getFieldCaption(Company::FIELD_NAME_COMPANY_ID);
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		$columnIndex = $fieldBindings->getColumnIndexByFieldId(self::ID);
		if ($columnIndex === null)
		{
			return FieldProcessResult::skip();
		}

		$vcardLineParts = $row[$columnIndex][0] ?? [];
		if (!is_array($vcardLineParts) || empty($vcardLineParts))
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

		$importItemFields['COMPANY'][Company::FIELD_NAME_TITLE] = $value;

		return FieldProcessResult::success();
	}
}

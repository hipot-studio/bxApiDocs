<?php

namespace Bitrix\Crm\Import\ImportEntityFields\VCard\Organization;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\ImportEntityFields\VCard\AbstractVCardField;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Import\Strategy\ValueMapper\VCard\FileValueMapper;
use Bitrix\Crm\Item\Company;
use Bitrix\Crm\VCard\VCardLine;
use Bitrix\Main\Localization\Loc;

final class CompanyLogo extends AbstractVCardField
{
	public const ID = 'COMPANY_LOGO';

	public function getId(): string
	{
		return self::ID;
	}

	public function getCaption(): string
	{
		return Loc::getMessage('CRM_IMPORT_VCARD_FIELD_COMPANY_LOGO');
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

		$file = (new FileValueMapper())->process($vcardLine);
		if ($file === null)
		{
			FieldProcessResult::skip();
		}

		$importItemFields['COMPANY'][Company::FIELD_NAME_LOGO] = $file;

		return FieldProcessResult::success();
	}
}

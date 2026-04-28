<?php

namespace Bitrix\Crm\Import\ImportEntityFields\VCard\Communication;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\ImportEntityFields\VCard\AbstractVCardField;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Item;
use Bitrix\Crm\Multifield\Type\Phone as PhoneMultifield;
use Bitrix\Crm\Multifield\TypeRepository;
use Bitrix\Crm\VCard\VCardLine;

final class Phone extends AbstractVCardField
{
	public const ID = 'PHONE';

	public function getId(): string
	{
		return self::ID;
	}

	public function getCaption(): string
	{
		return TypeRepository::getTypeCaption(PhoneMultifield::ID);
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		$columnIndex = $fieldBindings->getColumnIndexByFieldId(self::ID);
		if ($columnIndex === null)
		{
			return FieldProcessResult::skip();
		}

		$vcardLines = $row[$columnIndex] ?? [];
		if (!is_array($vcardLines) || empty($vcardLines))
		{
			return FieldProcessResult::skip();
		}

		$qty = 0;

		foreach ($vcardLines as $vcardParts)
		{
			$vcardLine = new VCardLine($vcardParts);
			if (!$vcardLine->validate()->isSuccess())
			{
				return FieldProcessResult::skip();
			}

			$value = $vcardLine->getValue();
			if (empty($value))
			{
				return FieldProcessResult::skip();
			}

			if ($vcardLine->hasParameterValue('VALUE', 'uri'))
			{
				$value = preg_replace("/[a-z]+:(\/\/)?/i", '', $value);
			}

			[$value, ] = explode(';', $value);

			$type = match (true) {
				$vcardLine->isType('work') => PhoneMultifield::VALUE_TYPE_WORK,

				$vcardLine->isType('cell') => PhoneMultifield::VALUE_TYPE_MOBILE,
				$vcardLine->isType('fax') => PhoneMultifield::VALUE_TYPE_FAX,
				$vcardLine->isType('home') => PhoneMultifield::VALUE_TYPE_HOME,
				$vcardLine->isType('pager') => PhoneMultifield::VALUE_TYPE_PAGER,

				$vcardLine->isType('video'),
				$vcardLine->isType('textphone'),
				$vcardLine->isType('text'),
				$vcardLine->isType('voice'),
				$vcardLine->isType('main-number') => PhoneMultifield::VALUE_TYPE_OTHER,

				default => PhoneMultifield::VALUE_TYPE_OTHER,
			};

			$importItemFields[Item::FIELD_NAME_FM][PhoneMultifield::ID] ??= [];
			$importItemFields[Item::FIELD_NAME_FM][PhoneMultifield::ID]["n{$qty}"] = [
				'VALUE' => $value,
				'VALUE_TYPE' => $type,
			];

			$qty++;
		}

		return FieldProcessResult::success();
	}
}

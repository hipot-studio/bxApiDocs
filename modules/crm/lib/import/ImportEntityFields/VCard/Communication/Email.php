<?php

namespace Bitrix\Crm\Import\ImportEntityFields\VCard\Communication;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\ImportEntityFields\VCard\AbstractVCardField;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Item;
use Bitrix\Crm\Multifield\Type\Email as EmailMultifield;
use Bitrix\Crm\Multifield\TypeRepository;
use Bitrix\Crm\VCard\VCardLine;

final class Email extends AbstractVCardField
{
	public const ID = 'EMAIL';

	public function getId(): string
	{
		return self::ID;
	}

	public function getCaption(): string
	{
		return TypeRepository::getTypeCaption(EmailMultifield::ID);
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		$columnIndex = $fieldBindings->getColumnIndexByFieldId(self::ID);
		if ($columnIndex === null)
		{
			return FieldProcessResult::skip();
		}

		$vcardLines = $row[$columnIndex] ?? [];

		$qty = 0;
		foreach ($vcardLines as $vcardParts)
		{
			$vcardLine = new VCardLine($vcardParts);
			if (!$vcardLine->validate()->isSuccess())
			{
				continue;
			}

			$value = $vcardLine->getValue();
			if (empty($value))
			{
				continue;
			}

			$type = match (true) {
				$vcardLine->isType('WORK') => EmailMultifield::VALUE_TYPE_WORK,
				$vcardLine->isType('HOME') => EmailMultifield::VALUE_TYPE_HOME,
				default => EmailMultifield::VALUE_TYPE_OTHER,
			};

			$importItemFields[Item::FIELD_NAME_FM][EmailMultifield::ID] ??= [];
			$importItemFields[Item::FIELD_NAME_FM][EmailMultifield::ID]["n{$qty}"] = [
				'VALUE' => $value,
				'VALUE_TYPE' => $type,
			];

			$qty++;
		}

		return FieldProcessResult::success();
	}
}

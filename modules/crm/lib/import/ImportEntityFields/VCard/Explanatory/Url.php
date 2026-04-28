<?php

namespace Bitrix\Crm\Import\ImportEntityFields\VCard\Explanatory;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\ImportEntityFields\VCard\AbstractVCardField;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Item;
use Bitrix\Crm\Multifield\Type\Web;
use Bitrix\Crm\Multifield\TypeRepository;
use Bitrix\Crm\VCard\VCardLine;

final class Url extends AbstractVCardField
{
	public const ID = 'URL';

	public function getId(): string
	{
		return self::ID;
	}

	public function getCaption(): string
	{
		return TypeRepository::getTypeCaption(Web::ID);
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
				continue;
			}

			$value = $vcardLine->getValue();
			if (empty($value))
			{
				continue;
			}

			$importItemFields[Item::FIELD_NAME_FM][Web::ID] ??= [];
			$importItemFields[Item::FIELD_NAME_FM][Web::ID]["n{$qty}"] = [
				'VALUE' => $value,
				'VALUE_TYPE' => Web::VALUE_TYPE_WORK,
			];

			$qty++;
		}

		return FieldProcessResult::success();
	}
}

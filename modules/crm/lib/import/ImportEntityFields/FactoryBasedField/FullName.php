<?php

namespace Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField;

use Bitrix\Crm\Format\PersonNameFormatter;
use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\ImportEntityFields\Trait\CanConfigureNameFormatTrait;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Item;
use CCrmStatus;

final class FullName extends AbstractFactoryBasedField
{
	use CanConfigureNameFormatTrait;

	private readonly Name $name;
	private readonly LastName $lastName;

	public function getId(): string
	{
		return Item::FIELD_NAME_FULL_NAME;
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		$columnIndex = $fieldBindings->getColumnIndexByFieldId($this->getId());
		if ($columnIndex === null)
		{
			return FieldProcessResult::skip();
		}

		$possibleFullName = $row[$columnIndex] ?? null;
		if (empty($possibleFullName))
		{
			return FieldProcessResult::skip();
		}

		$isParsed = PersonNameFormatter::tryParseName($possibleFullName, $this->nameFormat->value, $nameParts);
		if ($isParsed)
		{
			$importItemFields[Item::FIELD_NAME_NAME] = $nameParts['NAME'] ?? null;
			$importItemFields[Item::FIELD_NAME_SECOND_NAME] = $nameParts['SECOND_NAME'] ?? null;
			$importItemFields[Item::FIELD_NAME_LAST_NAME] = $nameParts['LAST_NAME'] ?? null;

			$honorific = $nameParts['TITLE'] ?? null;
			if (!empty($honorific))
			{
				$honorificList = CCrmStatus::GetStatusListEx('HONORIFIC');

				$honorificValue = array_search($honorific, $honorificList, false);
				if ($honorificValue !== false)
				{
					$importItemFields[Item::FIELD_NAME_HONORIFIC] = $honorificValue;
				}
			}
		}

		return FieldProcessResult::success();
	}
}

<?php

namespace Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Item;
use CCrmCurrency;

final class CurrencyId extends AbstractFactoryBasedField
{
	public function getId(): string
	{
		return Item::FIELD_NAME_CURRENCY_ID;
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		$columnIndex = $fieldBindings->getColumnIndexByFieldId($this->getId());
		if ($columnIndex === null)
		{
			return FieldProcessResult::skip();
		}

		$possibleCurrency = $row[$columnIndex] ?? null;
		if (empty($possibleCurrency))
		{
			return FieldProcessResult::skip();
		}

		$currency = CCrmCurrency::GetByName($possibleCurrency);
		if (!$currency)
		{
			$currency = CCrmCurrency::GetByID($possibleCurrency);
		}

		$importItemFields[$this->getId()] = $currency ? $currency['CURRENCY'] : CCrmCurrency::GetBaseCurrencyID();

		return FieldProcessResult::success();
	}
}

<?php

namespace Bitrix\Iblock\Infrastructure\Admin\ElementList\InlineEdit;

use Bitrix\Iblock\Public\Command\Catalog\PriceInput;
use Bitrix\Iblock\Public\Command\Catalog\PurchasingPriceInput;

/**
 * Parses catalog money cells of inline editing in the admin element lists.
 *
 * Money cells are posted as FIELDS[<row>][<column>_custom] lists of name/value pairs. CAdminUiList::EditAction()
 * keeps only FIELDS[<row>][...] names and writes them into the current row, so the cells are read from the request
 * as it was posted, before EditAction(). Only the structure of the request is checked here: the owners of the values
 * are checked when they are saved.
 */
final class RequestParser
{
	private const PRICE_COLUMN_MASK = '/^CATALOG_GROUP_([1-9][0-9]*)_custom$/D';
	private const PRICE_NAME_MASK
		= '/^(CATALOG_PRICE|CATALOG_CURRENCY|CATALOG_old_PRICE|CATALOG_old_CURRENCY|CATALOG_PRICE_ID'
		. '|CATALOG_QUANTITY_FROM|CATALOG_QUANTITY_TO|CATALOG_EXTRA)\[([1-9][0-9]*)\]\[([1-9][0-9]*)\]$/D'
	;
	private const PRICE_FIELDS = [
		'CATALOG_PRICE' => 'price',
		'CATALOG_CURRENCY' => 'currency',
		'CATALOG_old_PRICE' => 'oldPrice',
		'CATALOG_old_CURRENCY' => 'oldCurrency',
		'CATALOG_PRICE_ID' => 'priceId',
		'CATALOG_QUANTITY_FROM' => 'quantityFrom',
		'CATALOG_QUANTITY_TO' => 'quantityTo',
		'CATALOG_EXTRA' => 'extraId',
	];
	private const NUMERIC_FIELDS = [
		'CATALOG_PRICE_ID' => true,
		'CATALOG_QUANTITY_FROM' => true,
		'CATALOG_QUANTITY_TO' => true,
		'CATALOG_EXTRA' => true,
	];
	private const PURCHASING_COLUMN = 'CATALOG_PURCHASING_PRICE_custom';
	private const PURCHASING_FIELDS = '(CATALOG_PURCHASING_PRICE|CATALOG_PURCHASING_CURRENCY)';
	private const PURCHASING_ANY_TARGET_MASK
		= '/^FIELDS\\[([^\\]]*)\\]\\[(CATALOG_PURCHASING_PRICE|CATALOG_PURCHASING_CURRENCY)\\]$/D'
	;

	private string $rowPrefix;

	/**
	 * @param string $rowPrefix Prefix of element row ids in the grid ('E' in the mixed list, '' in the element list).
	 */
	public function __construct(string $rowPrefix)
	{
		$this->rowPrefix = $rowPrefix;
	}

	/**
	 * Parses the FIELDS list of the request as it was posted, before CAdminUiList::EditAction().
	 *
	 * @param mixed $rawFields
	 * @return InlineEditRequest
	 */
	public function parse(mixed $rawFields): InlineEditRequest
	{
		if (!is_array($rawFields))
		{
			return new InlineEditRequest();
		}

		$rowElementIds = [];
		$prices = [];
		$purchasingPrices = [];
		$foreignPurchasingCellRowIds = [];
		$rowMask = '/^' . preg_quote($this->rowPrefix, '/') . '([1-9][0-9]*)$/D';
		foreach ($rawFields as $rowId => $rowFields)
		{
			if (!is_array($rowFields) || !preg_match($rowMask, (string)$rowId, $matches))
			{
				continue;
			}
			$elementId = (int)$matches[1];
			$rowElementIds[] = $elementId;

			array_push($prices, ...$this->parsePrices($elementId, $rowFields));

			$purchasingInputs = $this->getPurchasingInputs($rowFields);
			if ($this->hasForeignPurchasingTarget($elementId, $purchasingInputs))
			{
				$foreignPurchasingCellRowIds[] = $elementId;
			}
			$purchasingPrice = $this->parseOfferPurchasingPrice($elementId, $purchasingInputs);
			if ($purchasingPrice !== null)
			{
				$purchasingPrices[] = $purchasingPrice;
			}
		}

		return new InlineEditRequest(
			rowElementIds: $rowElementIds,
			prices: $prices,
			purchasingPrices: $purchasingPrices,
			foreignPurchasingCellRowIds: $foreignPurchasingCellRowIds,
		);
	}

	/**
	 * Returns element rows that carry a purchasing price in the FIELDS list as CAdminUiList::EditAction() left it.
	 * Row ids are converted to element ids as the admin list scripts do.
	 *
	 * @param mixed $editedFields
	 * @return int[]
	 */
	public function getPurchasingPriceRowIds(mixed $editedFields): array
	{
		if (!is_array($editedFields))
		{
			return [];
		}

		$result = [];
		foreach ($editedFields as $rowId => $rowFields)
		{
			$rowId = (string)$rowId;
			if (
				!is_array($rowFields)
				|| !str_starts_with($rowId, $this->rowPrefix)
				|| (
					!array_key_exists('CATALOG_PURCHASING_PRICE', $rowFields)
					&& !array_key_exists('CATALOG_PURCHASING_CURRENCY', $rowFields)
				)
			)
			{
				continue;
			}
			$elementId = (int)substr($rowId, strlen($this->rowPrefix));
			if ($elementId > 0)
			{
				$result[] = $elementId;
			}
		}

		return $result;
	}

	/**
	 * @return PriceInput[]
	 */
	private function parsePrices(int $rowElementId, array $rowFields): array
	{
		$values = [];
		foreach ($rowFields as $column => $inputs)
		{
			if (!is_array($inputs) || !preg_match(self::PRICE_COLUMN_MASK, (string)$column, $matches))
			{
				continue;
			}
			$columnPriceTypeId = (int)$matches[1];
			foreach ($this->getInputValues($inputs) as $name => $value)
			{
				if (!preg_match(self::PRICE_NAME_MASK, $name, $matches))
				{
					continue;
				}
				[, $field, $targetId, $priceTypeId] = $matches;
				if ((int)$priceTypeId !== $columnPriceTypeId)
				{
					continue;
				}
				if (isset(self::NUMERIC_FIELDS[$field]) && !preg_match('/^[0-9]*$/D', $value))
				{
					continue;
				}
				$values[(int)$targetId][$columnPriceTypeId][self::PRICE_FIELDS[$field]] = $value;
			}
		}

		$result = [];
		foreach ($values as $targetId => $typeValues)
		{
			foreach ($typeValues as $priceTypeId => $fields)
			{
				$result[] = new PriceInput($rowElementId, $targetId, $priceTypeId, ...$fields);
			}
		}

		return $result;
	}

	private function getPurchasingInputs(array $rowFields): array
	{
		$inputs = $rowFields[self::PURCHASING_COLUMN] ?? null;

		return is_array($inputs) ? $this->getInputValues($inputs) : [];
	}

	private function hasForeignPurchasingTarget(int $rowElementId, array $inputs): bool
	{
		$rowId = $this->rowPrefix . $rowElementId;
		foreach (array_keys($inputs) as $name)
		{
			if (preg_match(self::PURCHASING_ANY_TARGET_MASK, (string)$name, $matches) && $matches[1] !== $rowId)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the changed purchasing price posted in a product row for another element (the selected offer).
	 */
	private function parseOfferPurchasingPrice(int $rowElementId, array $inputs): ?PurchasingPriceInput
	{
		$rowPrefix = preg_quote($this->rowPrefix, '/');
		$nameMask = '/^FIELDS\[' . $rowPrefix . '([1-9][0-9]*)\]\[' . self::PURCHASING_FIELDS . '\]$/D';
		$offerId = null;
		$values = [];
		foreach ($inputs as $name => $value)
		{
			if (!preg_match($nameMask, $name, $matches))
			{
				continue;
			}
			$targetId = (int)$matches[1];
			if ($offerId !== null && $offerId !== $targetId)
			{
				return null;
			}
			$offerId = $targetId;
			$values[$matches[2]] = $value;
		}

		if (
			$offerId === null
			|| $offerId === $rowElementId
			|| !isset($values['CATALOG_PURCHASING_PRICE'], $values['CATALOG_PURCHASING_CURRENCY'])
			|| $values['CATALOG_PURCHASING_CURRENCY'] === ''
			|| $this->isPurchasingPriceUnchanged($offerId, $values, $inputs)
		)
		{
			return null;
		}

		return new PurchasingPriceInput(
			rowElementId: $rowElementId,
			offerId: $offerId,
			price: $values['CATALOG_PURCHASING_PRICE'],
			currency: $values['CATALOG_PURCHASING_CURRENCY'],
		);
	}

	/**
	 * Mirrors CAdminList::IsUpdated(): the posted values are compared with FIELDS_OLD of the cell as strings.
	 * Without FIELDS_OLD the values are treated as changed. An empty price that stays empty is unchanged whatever
	 * the currency: the currency control of an empty cell gets the base currency, and in a product row without
	 * the "Product" column the cell shows the product values under the names of the selected offer.
	 */
	private function isPurchasingPriceUnchanged(int $offerId, array $values, array $inputs): bool
	{
		$oldPrefix = 'FIELDS_OLD[' . $this->rowPrefix . $offerId . ']';
		$oldPrice = $inputs[$oldPrefix . '[CATALOG_PURCHASING_PRICE]'] ?? null;
		$oldCurrency = $inputs[$oldPrefix . '[CATALOG_PURCHASING_CURRENCY]'] ?? null;
		if ($oldPrice === null || $oldCurrency === null)
		{
			return false;
		}
		if (trim($oldPrice) === '' && trim($values['CATALOG_PURCHASING_PRICE']) === '')
		{
			return true;
		}

		return
			$oldPrice === $values['CATALOG_PURCHASING_PRICE']
			&& $oldCurrency === $values['CATALOG_PURCHASING_CURRENCY'];
	}

	/**
	 * Mirrors the grid script: the first non-empty value of a repeated name wins.
	 */
	private function getInputValues(array $inputs): array
	{
		$result = [];
		foreach ($inputs as $input)
		{
			if (!is_array($input) || !is_string($input['name'] ?? null) || !is_string($input['value'] ?? null))
			{
				continue;
			}
			$name = $input['name'];
			if (!isset($result[$name]) || $result[$name] === '')
			{
				$result[$name] = $input['value'];
			}
		}

		return $result;
	}
}

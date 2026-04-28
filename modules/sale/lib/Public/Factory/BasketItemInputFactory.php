<?php

declare(strict_types=1);

namespace Bitrix\Sale\Public\Factory;

use Bitrix\Sale\BasketBase;
use Bitrix\Sale\BasketItem;
use Bitrix\Sale\Public\Dto\BasketItemCalculationInput;

/**
 * Factory for creating {@see BasketItemCalculationInput} from various sources.
 *
 * Handles format conversion (e.g. BasketItem stores VAT rate as a decimal 0.2,
 * while the public API expects a percentage 20).
 */
final class BasketItemInputFactory
{
	/**
	 * Decimal-to-percent multiplier for VAT rate conversion.
	 *
	 * BasketItem stores VAT rate as 0.2 for 20%; the public API expects 20.
	 */
	private const VAT_RATE_MULTIPLIER = 100;

	/**
	 * Create input DTO from a Sale BasketItem entity.
	 *
	 * @param BasketItem $basketItem Sale basket item.
	 *
	 * @return BasketItemCalculationInput Ready-to-calculate input.
	 */
	public function createFromBasketItem(BasketItem $basketItem): BasketItemCalculationInput
	{
		$basePrice = $basketItem->getBasePrice();
		$discountPrice = $basketItem->getDiscountPrice();

		$field = $basketItem->getField('VAT_INCLUDED');
		$vatIncluded = $field === 'Y' || $field === true;

		return new BasketItemCalculationInput(
			basePrice: $basePrice,
			quantity: $basketItem->getQuantity(),
			discountRate: null,
			discountValue: $discountPrice > 0 ? $discountPrice : null,
			vatRate: (float)$basketItem->getVatRate() * self::VAT_RATE_MULTIPLIER,
			vatIncluded: $vatIncluded,
		);
	}

	/**
	 * Create input DTO from a CRM product row array.
	 *
	 * CRM rows typically use keys like PRICE/BASE_PRICE, DISCOUNT_RATE, DISCOUNT_SUM,
	 * TAX_RATE, TAX_INCLUDED, etc.
	 *
	 * @param array $row CRM product row data.
	 *
	 * @return BasketItemCalculationInput Ready-to-calculate input.
	 */
	public function createFromCrmProductRow(array $row): BasketItemCalculationInput
	{
		$basePrice = (float)($row['PRICE'] ?? $row['BASE_PRICE'] ?? 0.0);
		$discountRate = isset($row['DISCOUNT_RATE']) ? (float)$row['DISCOUNT_RATE'] : null;
		$discountValue = isset($row['DISCOUNT_SUM']) ? (float)$row['DISCOUNT_SUM'] : null;

		$vatRate = (float)($row['TAX_RATE'] ?? $row['VAT_RATE'] ?? 0.0);
		$vatIncluded = !empty($row['TAX_INCLUDED']) || !empty($row['VAT_INCLUDED']);

		return new BasketItemCalculationInput(
			basePrice: $basePrice,
			quantity: (float)($row['QUANTITY'] ?? 1.0),
			discountRate: $discountRate,
			discountValue: $discountValue,
			vatRate: $vatRate,
			vatIncluded: $vatIncluded,
		);
	}

	/**
	 * Create input DTO from a generic associative array.
	 *
	 * Expected keys: basePrice, quantity, discountRate, discountValue,
	 * vatRate, vatIncluded.
	 *
	 * @param array $data Associative array with calculation parameters.
	 *
	 * @return BasketItemCalculationInput Ready-to-calculate input.
	 */
	public function createFromArray(array $data): BasketItemCalculationInput
	{
		return new BasketItemCalculationInput(
			basePrice: (float)($data['basePrice'] ?? 0.0),
			quantity: (float)($data['quantity'] ?? 1.0),
			discountRate: isset($data['discountRate']) ? (float)$data['discountRate'] : null,
			discountValue: isset($data['discountValue']) ? (float)$data['discountValue'] : null,
			vatRate: (float)($data['vatRate'] ?? 0.0),
			vatIncluded: (bool)($data['vatIncluded'] ?? true),
		);
	}

	/**
	 * Create an array of input DTOs from a BasketBase collection.
	 *
	 * Skips items that cannot be purchased.
	 *
	 * @param BasketBase $basket Basket collection.
	 *
	 * @return BasketItemCalculationInput[] Array of inputs.
	 */
	public function createFromBasketCollection(BasketBase $basket): array
	{
		$inputs = [];

		foreach ($basket as $basketItem)
		{
			if ($basketItem instanceof BasketItem && $basketItem->canBuy())
			{
				$inputs[] = $this->createFromBasketItem($basketItem);
			}
		}

		return $inputs;
	}
}

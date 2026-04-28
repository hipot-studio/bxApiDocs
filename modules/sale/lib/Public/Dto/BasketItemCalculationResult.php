<?php

declare(strict_types=1);

namespace Bitrix\Sale\Public\Dto;

/**
 * Immutable result of basket item price calculation.
 *
 * Contains all computed price components for a single basket line.
 * Row-level totals are available via the {@see $totalCalculation} property.
 */
final class BasketItemCalculationResult
{
	/**
	 * @param float $basePrice Original base price per unit (before discount).
	 * @param float $price Price per unit after discount.
	 * @param float $priceNetto Price per unit without VAT.
	 * @param float $priceBrutto Price per unit with VAT.
	 * @param float $discountValue Absolute discount amount per unit.
	 * @param float $discountRate Discount as a percentage (0–100).
	 * @param float $vatAmount VAT amount per unit.
	 * @param float $vatRate VAT rate in percent (e.g. 20 for 20%).
	 * @param bool $vatIncluded Whether VAT is included in the price.
	 * @param float $quantity Item quantity.
	 * @param BasketCalculationResultDto $totalCalculation Row-level aggregated totals (price * quantity).
	 */
	public function __construct(
		public readonly float $basePrice,
		public readonly float $price,
		public readonly float $priceNetto,
		public readonly float $priceBrutto,
		public readonly float $discountValue,
		public readonly float $discountRate,
		public readonly float $vatAmount,
		public readonly float $vatRate,
		public readonly bool $vatIncluded,
		public readonly float $quantity,
		public readonly BasketCalculationResultDto $totalCalculation,
	)
	{
	}
}

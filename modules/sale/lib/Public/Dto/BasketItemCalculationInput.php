<?php

declare(strict_types=1);

namespace Bitrix\Sale\Public\Dto;

/**
 * Immutable input DTO for basket item price calculation.
 *
 * VAT rate and discount rate are expressed as percentages (e.g. 20 means 20%).
 */
final class BasketItemCalculationInput
{
	/**
	 * @param float $basePrice Base unit price before discounts.
	 * @param float $quantity Item quantity (must be positive).
	 * @param float|null $discountRate Discount as a percentage (0–100), or null if not applicable.
	 * @param float|null $discountValue Absolute discount per unit, or null if not applicable.
	 * @param float $vatRate VAT rate in percent (e.g. 20 for 20%).
	 * @param bool $vatIncluded Whether VAT is already included in basePrice.
	 */
	public function __construct(
		public readonly float $basePrice,
		public readonly float $quantity = 1.0,
		public readonly ?float $discountRate = null,
		public readonly ?float $discountValue = null,
		public readonly float $vatRate = 0.0,
		public readonly bool $vatIncluded = true,
	)
	{
	}
}

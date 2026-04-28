<?php

declare(strict_types=1);

namespace Bitrix\Sale\Public\Contract;

use Bitrix\Sale\Public\Dto\BasketItemCalculationInput;
use Bitrix\Sale\Public\Dto\BasketItemCalculationResult;

/**
 * Contract for discount calculation operations.
 *
 * Provides methods for applying discounts (percentage and fixed),
 * computing discount rates, and reverse-calculating original prices.
 * Implementations must be stateless.
 */
interface DiscountCalculatorInterface
{
	/**
	 * Calculate discount rate percentage from original and final prices.
	 *
	 * @param float $originalPrice Original price.
	 * @param float $finalPrice Final price after discount.
	 *
	 * @return float Discount rate as percentage (0-100).
	 */
	public function calculateDiscountRate(float $originalPrice, float $finalPrice): float;

	/**
	 * Calculate original price from a discounted price and discount rate.
	 *
	 * @param float $discountedPrice Price after discount.
	 * @param float $discountRate Discount rate as percentage (0-100).
	 *
	 * @return float Original price before discount.
	 */
	public function calculateOriginalPrice(float $discountedPrice, float $discountRate): float;
}

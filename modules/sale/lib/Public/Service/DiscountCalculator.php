<?php

declare(strict_types=1);

namespace Bitrix\Sale\Public\Service;

use Bitrix\Sale\PriceMaths;
use Bitrix\Sale\Public\Contract\DiscountCalculatorInterface;

/**
 * Single source of truth for standalone discount calculations.
 *
 * Provides discount-specific operations: rate calculation,
 * original price recovery, and absolute discount value from rate.
 * All rounding is delegated to {@see PriceMaths::roundPrecision()}.
 */
final class DiscountCalculator implements DiscountCalculatorInterface
{
	/**
	 * {@inheritDoc}
	 */
	public function calculateDiscountRate(float $originalPrice, float $finalPrice): float
	{
		if ($originalPrice === 0.0)
		{
			return 0.0;
		}

		if ($finalPrice === 0.0)
		{
			return $originalPrice > 0 ? 100.0 : -100.0;
		}

		$rate = (100 * ($originalPrice - $finalPrice)) / $originalPrice;

		return PriceMaths::roundPrecision($rate);
	}

	/**
	 * {@inheritDoc}
	 */
	public function calculateOriginalPrice(float $discountedPrice, float $discountRate): float
	{
		if ($discountRate === 100.0)
		{
			return 0.0;
		}

		$originalAmount = (100 * $discountedPrice) / (100 - $discountRate);

		return PriceMaths::roundPrecision($originalAmount);
	}

	/**
	 * Calculate the absolute discount value from a base price and rate.
	 *
	 * @param float $basePrice Original price before discount.
	 * @param float $discountRate Discount rate as percentage (0-100).
	 *
	 * @return float Absolute discount amount.
	 */
	public function calculateDiscountValue(float $basePrice, float $discountRate): float
	{
		if ($basePrice === 0.0 || $discountRate === 0.0)
		{
			return 0.0;
		}

		$discountAmount = $basePrice * $discountRate / 100;

		return PriceMaths::roundPrecision($discountAmount);
	}
}

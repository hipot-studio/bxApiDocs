<?php

declare(strict_types=1);

namespace Bitrix\Sale\Public\Service;

use Bitrix\Sale\PriceMaths;
use Bitrix\Sale\Public\Contract\PriceRounderInterface;

/**
 * Single source of truth for price rounding operations.
 *
 * Delegates all rounding to {@see PriceMaths} while providing
 * a service-oriented interface for cross-module consumption.
 */
final class PriceRounder implements PriceRounderInterface
{
	/**
	 * {@inheritDoc}
	 */
	public function roundPrecision(float $value): float
	{
		return PriceMaths::roundPrecision($value);
	}

	/**
	 * {@inheritDoc}
	 */
	public function roundByFormatCurrency(float $price, string $currency, ?int $limitRounding = null): float
	{
		return PriceMaths::roundByFormatCurrency($price, $currency, $limitRounding);
	}

	/**
	 * {@inheritDoc}
	 */
	public function getPrecision(): int
	{
		return PriceMaths::getCurrentPrecision();
	}
}

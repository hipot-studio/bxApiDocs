<?php

declare(strict_types=1);

namespace Bitrix\Sale\Public\Contract;

/**
 * Contract for price rounding operations.
 *
 * Provides methods for rounding monetary values to the configured precision
 * and by currency display format. Implementations must be stateless.
 */
interface PriceRounderInterface
{
	/**
	 * Round value to the current working precision.
	 *
	 * @param float $value Value to round.
	 *
	 * @return float Rounded value.
	 */
	public function roundPrecision(float $value): float;

	/**
	 * Round price by currency display format.
	 *
	 * @param float $price Price value.
	 * @param string $currency Currency code.
	 * @param int|null $limitRounding Optional limit for rounding.
	 *
	 * @return float Rounded price.
	 */
	public function roundByFormatCurrency(float $price, string $currency, ?int $limitRounding = null): float;

	/**
	 * Return the current working precision (number of decimal places).
	 *
	 * @return int Precision value.
	 */
	public function getPrecision(): int;
}

<?php

declare(strict_types=1);

namespace Bitrix\Sale\Public\Service;

use Bitrix\Sale\PriceMaths;
use Bitrix\Sale\Public\Contract\VatCalculatorInterface;
use Bitrix\Sale\Public\Dto\BasketItemCalculationInput;

/**
 * Single source of truth for standalone VAT calculations.
 *
 * Provides VAT computation without requiring a full basket item context.
 * All rounding is delegated to {@see PriceMaths::roundPrecision()}.
 */
final class VatCalculator implements VatCalculatorInterface
{
	/**
	 * {@inheritDoc}
	 */
	public function calculateVatAmount(BasketItemCalculationInput $input): float
	{
		if ($input->vatRate <= 0)
		{
			return 0.0;
		}

		$vatAmount =
			$input->vatIncluded
				? $this->extractVatFromGross($input->basePrice, $input->vatRate)
				: $this->computeVatFromNet($input->basePrice, $input->vatRate)
		;

		return PriceMaths::roundPrecision($vatAmount);
	}

	/**
	 * {@inheritDoc}
	 */
	public function allocateVat(BasketItemCalculationInput $input): float
	{
		if ($input->vatRate <= 0)
		{
			return PriceMaths::roundPrecision($input->basePrice);
		}

		$netto =
			$input->vatIncluded
				? $input->basePrice / (1 + $input->vatRate / 100)
				: $input->basePrice
		;

		return PriceMaths::roundPrecision($netto);
	}

	/**
	 * {@inheritDoc}
	 */
	public function accrueVat(BasketItemCalculationInput $input): float
	{
		if ($input->vatRate <= 0)
		{
			return PriceMaths::roundPrecision($input->basePrice);
		}

		$brutto =
			$input->vatIncluded
				? $input->basePrice
				: $input->basePrice * (1 + $input->vatRate / 100)
		;

		return PriceMaths::roundPrecision($brutto);
	}

	/**
	 * Extract VAT amount from a gross (VAT-inclusive) price.
	 */
	private function extractVatFromGross(float $price, float $vatRate): float
	{
		return $price - ($price / (1 + $vatRate / 100));
	}

	/**
	 * Compute VAT amount from a net (VAT-exclusive) price.
	 */
	private function computeVatFromNet(float $price, float $vatRate): float
	{
		return $price * ($vatRate / 100);
	}
}

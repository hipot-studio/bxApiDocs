<?php

declare(strict_types=1);

namespace Bitrix\Sale\Public\Service;

use Bitrix\Sale\PriceMaths;
use Bitrix\Sale\Public\Contract\BasketItemCalculatorInterface;
use Bitrix\Sale\Public\Dto\BasketCalculationResultDto;
use Bitrix\Sale\Public\Dto\BasketItemCalculationInput;
use Bitrix\Sale\Public\Dto\BasketItemCalculationResult;

/**
 * Single source of truth for basket item price calculation.
 *
 * Computes discount, netto/brutto prices, and VAT for an individual line item.
 * Discount resolution is delegated to {@see DiscountCalculator}.
 * VAT computation is delegated to {@see VatCalculator}.
 * All rounding is delegated to {@see PriceMaths::roundPrecision()}.
 */
final class BasketItemCalculator implements BasketItemCalculatorInterface
{
	private const MIN_PRICE = 0.0;

	private DiscountCalculator $discountCalculator;
	private VatCalculator $vatCalculator;

	public function __construct(
		?DiscountCalculator $discountCalculator = null,
		?VatCalculator $vatCalculator = null,
	)
	{
		$this->discountCalculator = $discountCalculator ?? new DiscountCalculator();
		$this->vatCalculator = $vatCalculator ?? new VatCalculator();
	}

	/**
	 * {@inheritDoc}
	 */
	public function calculate(BasketItemCalculationInput $input): BasketItemCalculationResult
	{
		$discountAmount = $this->calculateDiscountValue($input->basePrice, $input->discountRate, $input->discountValue);
		$priceAfterDiscount = max(self::MIN_PRICE, $input->basePrice - $discountAmount);

		$vatInput = new BasketItemCalculationInput(
			basePrice: $priceAfterDiscount,
			vatRate: $input->vatRate,
			vatIncluded: $input->vatIncluded,
		);
		$priceNetto = $this->vatCalculator->allocateVat($vatInput);
		$priceBrutto = $this->vatCalculator->accrueVat($vatInput);
		$vatAmount = $this->vatCalculator->calculateVatAmount($vatInput);

		$effectiveDiscountRate = $this->calculateDiscountRate(
			$input->basePrice,
			$discountAmount,
			$input->discountRate,
		);

		$roundedBasePrice = PriceMaths::roundPrecision($input->basePrice);

		$unitFinalPrice = $input->vatIncluded ? $priceAfterDiscount : $priceBrutto;

		$totalCalculation = new BasketCalculationResultDto(
			items: [],
			totalBasePrice: PriceMaths::roundPrecision($input->basePrice * $input->quantity),
			totalDiscountValue: PriceMaths::roundPrecision($discountAmount * $input->quantity),
			totalPrice: PriceMaths::roundPrecision($unitFinalPrice * $input->quantity),
			totalVatValue: PriceMaths::roundPrecision($vatAmount * $input->quantity),
			totalNetto: PriceMaths::roundPrecision($priceNetto * $input->quantity),
			itemCount: 1,
		);

		return new BasketItemCalculationResult(
			basePrice: $roundedBasePrice,
			price: PriceMaths::roundPrecision($priceAfterDiscount),
			priceNetto: PriceMaths::roundPrecision($priceNetto),
			priceBrutto: PriceMaths::roundPrecision($priceBrutto),
			discountValue: PriceMaths::roundPrecision($discountAmount),
			discountRate: PriceMaths::roundPrecision($effectiveDiscountRate),
			vatAmount: PriceMaths::roundPrecision($vatAmount),
			vatRate: $input->vatRate,
			vatIncluded: $input->vatIncluded,
			quantity: $input->quantity,
			totalCalculation: $totalCalculation,
		);
	}

	/**
	 * Resolve absolute discount amount using explicit value or percentage rate.
	 *
	 * Priority: absolute value takes precedence over percentage rate.
	 * The result is capped at basePrice to prevent negative prices.
	 *
	 * @param float $basePrice Original price before discount.
	 * @param float|null $discountRate Discount rate as percentage (0-100).
	 * @param float|null $discountValue Absolute discount amount.
	 *
	 * @return float Resolved absolute discount amount.
	 */
	private function calculateDiscountValue(float $basePrice, ?float $discountRate, ?float $discountValue): float
	{
		if ($discountValue !== null && $discountValue > 0)
		{
			return min($discountValue, $basePrice);
		}

		if ($discountRate !== null && $discountRate > 0)
		{
			$discountAmount = $this->discountCalculator->calculateDiscountValue($basePrice, $discountRate);

			return min($discountAmount, $basePrice);
		}

		return 0.0;
	}

	/**
	 * Derive the discount percentage from base price and resolved discount amount.
	 *
	 * When an explicit input rate is provided it is returned as-is.
	 *
	 * @param float $basePrice Original price before discount.
	 * @param float $discountAmount Resolved absolute discount amount.
	 * @param float|null $inputRate Original discount rate if provided explicitly.
	 *
	 * @return float Effective discount rate as percentage.
	 */
	private function calculateDiscountRate(float $basePrice, float $discountAmount, ?float $inputRate): float
	{
		if ($inputRate !== null)
		{
			return $inputRate;
		}

		if ($basePrice <= 0 || $discountAmount <= 0)
		{
			return 0.0;
		}

		$finalPrice = $basePrice - $discountAmount;

		return $this->discountCalculator->calculateDiscountRate($basePrice, $finalPrice);
	}
}

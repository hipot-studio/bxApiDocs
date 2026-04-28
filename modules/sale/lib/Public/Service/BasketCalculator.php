<?php

declare(strict_types=1);

namespace Bitrix\Sale\Public\Service;

use Bitrix\Sale\PriceMaths;
use Bitrix\Sale\Public\Contract\BasketItemCalculatorInterface;
use Bitrix\Sale\Public\Dto\BasketCalculationResultDto;
use Bitrix\Sale\Public\Dto\BasketItemCalculationInput;

/**
 * Aggregates individual item calculations into basket-level totals.
 */
final class BasketCalculator
{
	private BasketItemCalculatorInterface $itemCalculator;

	public function __construct(?BasketItemCalculatorInterface $itemCalculator = null)
	{
		$this->itemCalculator = $itemCalculator ?? new BasketItemCalculator();
	}

	/**
	 * Calculate all items and return aggregated basket result.
	 *
	 * @param BasketItemCalculationInput[] $inputs Item inputs.
	 *
	 * @return BasketCalculationResultDto Aggregated basket totals.
	 */
	public function calculate(array $inputs): BasketCalculationResultDto
	{
		$itemResults = [];
		$totalBasePrice = 0.0;
		$totalDiscountValue = 0.0;
		$totalPrice = 0.0;
		$totalVatValue = 0.0;
		$totalNetto = 0.0;

		foreach ($inputs as $input)
		{
			if (!$input instanceof BasketItemCalculationInput)
			{
				continue;
			}

			$result = $this->itemCalculator->calculate($input);
			$itemResults[] = $result;

			$totalBasePrice += $result->totalCalculation->totalBasePrice;
			$totalDiscountValue += $result->totalCalculation->totalDiscountValue;
			$totalPrice += $result->totalCalculation->totalPrice;
			$totalVatValue += $result->totalCalculation->totalVatValue;
			$totalNetto += $result->totalCalculation->totalNetto;
		}

		return new BasketCalculationResultDto(
			items: $itemResults,
			totalBasePrice: PriceMaths::roundPrecision($totalBasePrice),
			totalDiscountValue: PriceMaths::roundPrecision($totalDiscountValue),
			totalPrice: PriceMaths::roundPrecision($totalPrice),
			totalVatValue: PriceMaths::roundPrecision($totalVatValue),
			totalNetto: PriceMaths::roundPrecision($totalNetto),
			itemCount: count($itemResults),
		);
	}
}

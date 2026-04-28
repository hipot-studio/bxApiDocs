<?php

declare(strict_types=1);

namespace Bitrix\Sale\Public\Contract;

use Bitrix\Sale\Public\Dto\BasketItemCalculationInput;
use Bitrix\Sale\Public\Dto\BasketItemCalculationResult;

/**
 * Contract for basket item price calculation.
 *
 * Provides a single entry point for computing price, discount, and VAT
 * for an individual basket item. Implementations must be stateless.
 */
interface BasketItemCalculatorInterface
{
	/**
	 * Calculate all price components for a single basket item.
	 *
	 * @param BasketItemCalculationInput $input Item parameters (price, quantity, discount, VAT).
	 *
	 * @return BasketItemCalculationResult Immutable result with all computed price components.
	 */
	public function calculate(BasketItemCalculationInput $input): BasketItemCalculationResult;
}

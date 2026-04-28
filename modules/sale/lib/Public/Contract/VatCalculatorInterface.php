<?php

declare(strict_types=1);

namespace Bitrix\Sale\Public\Contract;

use Bitrix\Sale\Public\Dto\BasketItemCalculationInput;

/**
 * Contract for VAT calculation operations.
 *
 * Provides methods for computing VAT amounts, extracting VAT from gross prices,
 * and accruing VAT onto net prices. Implementations must be stateless.
 */
interface VatCalculatorInterface
{
	/**
	 * Calculate VAT amount from price.
	 *
	 * @param BasketItemCalculationInput $input VAT calculation parameters.
	 *
	 * @return float Absolute VAT amount.
	 */
	public function calculateVatAmount(BasketItemCalculationInput $input): float;

	/**
	 * Extract price without VAT from a gross price (VAT-inclusive).
	 *
	 * @param BasketItemCalculationInput $input VAT calculation parameters (vatIncluded must be true).
	 *
	 * @return float Price without VAT (netto).
	 */
	public function allocateVat(BasketItemCalculationInput $input): float;

	/**
	 * Accrue VAT onto a net price (VAT-exclusive).
	 *
	 * @param BasketItemCalculationInput $input VAT calculation parameters (vatIncluded must be false).
	 *
	 * @return float Price with VAT (brutto).
	 */
	public function accrueVat(BasketItemCalculationInput $input): float;
}

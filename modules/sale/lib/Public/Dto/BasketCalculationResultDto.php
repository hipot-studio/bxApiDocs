<?php

declare(strict_types=1);

namespace Bitrix\Sale\Public\Dto;

/**
 * Immutable result of basket-level calculation.
 *
 * Contains aggregated totals and per-item results for the entire basket.
 */
final class BasketCalculationResultDto
{
	/**
	 * @param BasketItemCalculationResult[] $items Per-item calculation results.
	 * @param float $totalBasePrice Sum of base prices (before discounts) for all items.
	 * @param float $totalDiscountValue Sum of discount amounts for all items.
	 * @param float $totalPrice Sum of final prices for all items.
	 * @param float $totalVatValue Sum of VAT amounts for all items.
	 * @param float $totalNetto Sum of netto prices (without VAT) for all items.
	 * @param int $itemCount Number of calculated items.
	 */
	public function __construct(
		public readonly array $items,
		public readonly float $totalBasePrice,
		public readonly float $totalDiscountValue,
		public readonly float $totalPrice,
		public readonly float $totalVatValue,
		public readonly float $totalNetto,
		public readonly int $itemCount,
	)
	{
	}
}

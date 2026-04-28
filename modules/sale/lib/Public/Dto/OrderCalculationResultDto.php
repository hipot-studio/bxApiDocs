<?php

declare(strict_types=1);

namespace Bitrix\Sale\Public\Dto;

/**
 * Order Calculation Result DTO
 * Contains aggregated calculation results for order including basket and delivery
 */
class OrderCalculationResultDto
{
	public function __construct(
		public readonly BasketCalculationResultDto $basket,
		public readonly float $deliveryPrice,
		public readonly float $deliveryVatValue,
		public readonly float $totalPrice,
		public readonly float $totalVatValue,
	)
	{
	}

	public function getTotalWithoutVat(): float
	{
		return $this->totalPrice - $this->totalVatValue;
	}

	public function getBasketPrice(): float
	{
		return $this->basket->totalPrice;
	}

	public function getBasketVatValue(): float
	{
		return $this->basket->totalVatValue;
	}
}

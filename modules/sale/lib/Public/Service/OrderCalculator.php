<?php

declare(strict_types=1);

namespace Bitrix\Sale\Public\Service;

use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\Sale\Order;
use Bitrix\Sale\PriceMaths;
use Bitrix\Sale\Public\Dto\OrderCalculationResultDto;
use Bitrix\Sale\Public\Factory\BasketItemInputFactory;

/**
 * Calculates order totals: basket items + delivery costs.
 *
 * Delegates item-level math to {@see BasketCalculator} and aggregates
 * delivery shipment costs on top.
 */
final class OrderCalculator
{
	private BasketCalculator $basketCalculator;
	private BasketItemInputFactory $inputFactory;
	private BasketItemCalculator $itemCalculator;

	public function __construct(
		?BasketCalculator $basketCalculator = null,
		?BasketItemInputFactory $inputFactory = null,
		?BasketItemCalculator $itemCalculator = null,
	)
	{
		$this->itemCalculator = $itemCalculator ?? new BasketItemCalculator();
		$this->basketCalculator = $basketCalculator ?? new BasketCalculator($this->itemCalculator);
		$this->inputFactory = $inputFactory ?? new BasketItemInputFactory();
	}

	/**
	 * Calculate complete order including basket and delivery.
	 *
	 * @param Order $order Sale order entity.
	 *
	 * @return Result Data key "order" contains {@see OrderCalculationResultDto}.
	 */
	public function calculate(Order $order): Result
	{
		$result = new Result();

		try
		{
			$basket = $order->getBasket();
			$inputs = $this->inputFactory->createFromBasketCollection($basket);
			$basketResult = $this->basketCalculator->calculate($inputs);

			$deliveryPrice = 0.0;
			$deliveryVatValue = 0.0;

			$shipmentCollection = $order->getShipmentCollection();
			if ($shipmentCollection)
			{
				foreach ($shipmentCollection as $shipment)
				{
					if ($shipment->isSystem())
					{
						continue;
					}

					$shipmentPrice = (float)$shipment->getPrice();
					$deliveryPrice += $shipmentPrice;

					$shipmentVatRate = (float)$shipment->getVatRate();
					if ($shipmentVatRate > 0)
					{
						$isVatIncluded = $shipment->isVatInPrice();
						$deliveryVatValue += $this->computeVatAmount(
							$shipmentPrice,
							$shipmentVatRate,
							$isVatIncluded,
						);
					}
				}
			}

			$totalPrice = $basketResult->totalPrice + $deliveryPrice;
			$totalVatValue = $basketResult->totalVatValue + $deliveryVatValue;

			$orderResult = new OrderCalculationResultDto(
				basket: $basketResult,
				deliveryPrice: PriceMaths::roundPrecision($deliveryPrice),
				deliveryVatValue: PriceMaths::roundPrecision($deliveryVatValue),
				totalPrice: PriceMaths::roundPrecision($totalPrice),
				totalVatValue: PriceMaths::roundPrecision($totalVatValue),
			);

			$result->setData(['order' => $orderResult]);
		}
		catch (\Exception $e)
		{
			$result->addError(new Error($e->getMessage()));
		}

		return $result;
	}

	/**
	 * Compute VAT amount for a delivery shipment price.
	 *
	 * Reuses the same formulas as {@see BasketItemCalculator}.
	 */
	private function computeVatAmount(float $price, float $vatRate, bool $vatIncluded): float
	{
		if ($vatRate <= 0)
		{
			return 0.0;
		}

		if ($vatIncluded)
		{
			$priceNetto = $price / (1 + $vatRate / 100);

			return PriceMaths::roundPrecision($price - $priceNetto);
		}

		$priceBrutto = $price * (1 + $vatRate / 100);

		return PriceMaths::roundPrecision($priceBrutto - $price);
	}
}

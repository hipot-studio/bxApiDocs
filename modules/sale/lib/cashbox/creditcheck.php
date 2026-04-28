<?php

namespace Bitrix\Sale\Cashbox;

use Bitrix\Main;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Sale;
use Bitrix\Sale\Public\Dto\BasketItemCalculationInput;

Main\Localization\Loc::loadMessages(__FILE__);

/**
 * Class CreditCheck
 * @package Bitrix\Sale\Cashbox
 */

class CreditCheck extends Check
{
	/**
	 * @return string
	 */
	public static function getType()
	{
		return 'credit';
	}

	/**
	 * @return string
	 */
	public static function getName()
	{
		return Main\Localization\Loc::getMessage('SALE_CASHBOX_CREDIT_NAME');
	}

	/**
	 * @return string
	 */
	public static function getCalculatedSign()
	{
		return static::CALCULATED_SIGN_INCOME;
	}

	/**
	 * @return string
	 */
	public static function getSupportedEntityType()
	{
		return static::SUPPORTED_ENTITY_TYPE_SHIPMENT;
	}

	/**
	 * Set entities and calculate sum using centralized calculation services.
	 *
	 * @param array $entities
	 * @throws Main\ArgumentException
	 * @throws Main\ArgumentNullException
	 * @throws Main\ArgumentTypeException
	 * @throws Main\ObjectNotFoundException
	 */
	public function setEntities(array $entities)
	{
		parent::setEntities($entities);

		foreach ($entities as $entity)
		{
			if ($entity instanceof Sale\Shipment)
			{
				$this->setField('SHIPMENT_ID', $entity->getId());

				if (!$this->getField('CURRENCY'))
				{
					$this->setField('CURRENCY', $entity->getOrder()->getCurrency());
				}

				$sum = $this->calculateShipmentSum($entity);
				$this->setField('SUM', $sum);
			}
		}
	}

	/**
	 * Calculate total sum for shipment including delivery and items.
	 *
	 * Uses BasketItemCalculator service for consistent calculations.
	 *
	 * @param Sale\Shipment $shipment
	 * @return float Total sum rounded to precision
	 */
	protected function calculateShipmentSum(Sale\Shipment $shipment): float
	{
		$sum = $shipment->getPrice();
		$shipmentItemCollection = $shipment->getShipmentItemCollection();
		$calculator = ServiceLocator::getInstance()->get('sale.basketItemCalculator');

		/** @var Sale\ShipmentItem $item */
		foreach ($shipmentItemCollection as $item)
		{
			$basketItem = $item->getBasketItem();
			if ($basketItem === null)
			{
				continue;
			}

			$input = new BasketItemCalculationInput(
				basePrice: (float)$basketItem->getPrice(),
				quantity: (float)$item->getQuantity(),
			);

			$result = $calculator->calculate($input);
			$sum += $result->totalCalculation->totalPrice;
		}

		return Sale\PriceMaths::roundPrecision($sum);
	}

	/**
	 * @return array
	 */
	protected function extractDataInternal()
	{
		$result = parent::extractDataInternal();

		$totalSum = 0;
		if (isset($result['PRODUCTS']))
		{
			foreach ($result['PRODUCTS'] as $item)
				$totalSum += $item['SUM'];
		}

		if (isset($result['DELIVERY']))
		{
			foreach ($result['DELIVERY'] as $item)
				$totalSum += $item['SUM'];
		}

		$result['PAYMENTS'] = array(
			array(
				'TYPE' => static::PAYMENT_TYPE_CREDIT,
				'SUM' => $totalSum
			)
		);

		$result['TOTAL_SUM'] = $totalSum;

		return $result;
	}

	/**
	 * @return string
	 */
	public static function getSupportedRelatedEntityType()
	{
		return static::SUPPORTED_ENTITY_TYPE_NONE;
	}

}
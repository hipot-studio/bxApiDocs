<?php

namespace Bitrix\Sale\Services\PaySystem\Restrictions;

use Bitrix\Sale\Internals\Entity;
use Bitrix\Sale\Payment;

trait PayableBasketItemsTrait
{
	/**
	 * @param Payment $entity
	 * @return array
	 */
	protected static function getBasketItems(Entity $entity): array
	{
		if (!$entity instanceof Payment)
		{
			return [];
		}

		$basketItems = [];

		$payableItemCollection = $entity->getPayableItemCollection();
		if ($payableItemCollection->isEmpty())
		{
			/** @var $collection \Bitrix\Sale\PaymentCollection */
			if (!$collection = $entity->getCollection())
			{
				return [];
			}

			/** @var $order \Bitrix\Sale\Order */
			if (!$order =  $collection->getOrder())
			{
				return [];
			}

			/** @var $orderBasket \Bitrix\Sale\Basket */
			if ($basket = $order->getBasket())
			{
				return $basket->getBasketItems();
			}
		}
		else
		{
			$basketItemCollection = $payableItemCollection->getBasketItems();

			/** @var \Bitrix\Sale\PayableBasketItem $payableBasketItem */
			foreach ($basketItemCollection as $payableBasketItem)
			{
				$basketItem = $payableBasketItem->getEntityObject();
				if (!$basketItem)
				{
					continue;
				}

				$basketItems[] = $basketItem;
			}
		}

		return $basketItems;
	}
}

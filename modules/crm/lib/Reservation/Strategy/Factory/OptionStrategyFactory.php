<?php

namespace Bitrix\Crm\Reservation\Strategy\Factory;

use Bitrix\Crm\Integration\Sale\Reservation\Config\EntityFactory;
use Bitrix\Crm\Integration\Sale\Reservation\Config\Entity\Deal;
use Bitrix\Crm\Integration\Sale\Reservation\Config\Entity\Entity;
use Bitrix\Crm\Reservation\Strategy\ManualStrategy;
use Bitrix\Crm\Reservation\Strategy\ReservePaidProductsStrategy;
use Bitrix\Crm\Reservation\Strategy\ReserveQuantityEqualProductQuantityStrategy;
use Bitrix\Crm\Reservation\Strategy\Strategy;
use CCrmSaleHelper;
use Bitrix\Catalog\Store\EnableWizard\Manager;

/**
 * Create strategy by configuration options.
 *
 * For example:
 * ```php
 * $strategy = (new OptionStrategyFactory)->create();
 * ```
 */
class OptionStrategyFactory
{
	/**
	 * Used reservation strategy or not.
	 *
	 * @return bool
	 */
	private function isUsedStrategy(): bool
	{
		return CCrmSaleHelper::isProcessInventoryManagement();
	}

	/**
	 * Create an instance if possible.
	 *
	 * @return Strategy|null
	 */
	public function create(): ?Strategy
	{
		$dealConfig = EntityFactory::make(Deal::CODE);

		if (!$this->isUsedStrategy())
		{
			return null;
		}
		elseif (Manager::isOnecMode())
		{
			return new ManualStrategy();
		}
		elseif ($dealConfig->getReservationMode() === Entity::RESERVATION_MODE_OPTION_ON_ADD_TO_DOCUMENT)
		{
			return new ReserveQuantityEqualProductQuantityStrategy();
		}
		elseif ($dealConfig->getReservationMode() === Entity::RESERVATION_MODE_OPTION_ON_PAYMENT)
		{
			return new ReservePaidProductsStrategy();
		}

		return new ManualStrategy();
	}
}

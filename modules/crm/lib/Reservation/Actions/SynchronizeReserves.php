<?php

namespace Bitrix\Crm\Reservation\Actions;

use Bitrix\Crm\Order\OrderDealSynchronizer;
use Bitrix\Crm\ProductRowCollection;
use Bitrix\Crm\Reservation\ProductRowReservation;
use Bitrix\Crm\Reservation\Strategy\Reserve\ReservationResult;
use Bitrix\Crm\Service\Operation\Action;
use Bitrix\Crm\Service\Sale\Reservation\ReservationService;
use Bitrix\Main\ORM\Objectify\EntityObject;
use Bitrix\Main\ORM\Objectify\Values;
use CCrmOwnerType;

/**
 * Base action of synchronize `crm` and `sale` reserves.
 */
abstract class SynchronizeReserves extends Action
{
	protected ReservationResult $reservationResult;
	private array $processedRowsIds = [];

	/**
	 * Fill reserve info by processed product rows.
	 *
	 * @param ProductRowCollection $productRows
	 *
	 * @return void
	 */
	protected function fillReservationResult(ProductRowCollection $productRows): void
	{
		$this->reservationResult ??= new ReservationResult();

		foreach ($productRows as $row)
		{
			$rowId = (int)($row->getId() ?? 0);
			if (!$rowId)
			{
				continue;
			}
			elseif (isset($this->processedRowsIds[$rowId]))
			{
				continue;
			}

			// saving processed rows
			$this->processedRowsIds[$rowId] = true;

			/**
			 * @var ProductRowReservation|EntityObject $productReservation
			 */
			$productReservation = $row->getProductRowReservation();
			if (!$productReservation)
			{
				continue;
			}

			// if empty the store, that we don't know where create reserve.
			if (empty($productReservation->getStoreId()))
			{
				continue;
			}

			$this->fillReservationResultRow($rowId, $productReservation);
		}
	}

	abstract protected function fillReservationResultRow(int $rowId, ProductRowReservation $productReservation): void;

	/**
	 * Save reserve infos.
	 *
	 * If not information for reserves, calls general reservation operation for products,
	 * to works the reservation strategies.
	 *
	 * @param int $dealId
	 *
	 * @return void
	 */
	protected function synchronizeReserves(int $dealId): void
	{
		if (!isset($this->reservationResult) || empty($this->reservationResult->getChangedReserveInfos()))
		{
			ReservationService::getInstance()->reservationProducts(CCrmOwnerType::Deal, $dealId);
		}
		else
		{
			$synchronizer = new OrderDealSynchronizer();
			$synchronizer->syncOrderReservesFromDeal($dealId, $this->reservationResult, true);
		}
	}
}

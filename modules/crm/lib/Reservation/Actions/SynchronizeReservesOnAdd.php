<?php

namespace Bitrix\Crm\Reservation\Actions;

use Bitrix\Crm\Item;
use Bitrix\Crm\ProductRowCollection;
use Bitrix\Crm\Reservation\ProductRowReservation;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Objectify\Values;
use Bitrix\Main\Result;

/**
 * Synchronize reserves on create deal.
 *
 * Used only after saving.
 */
class SynchronizeReservesOnAdd extends SynchronizeReserves
{
	/**
	 * @inheritDoc
	 */
	public function process(Item $item): Result
	{
		$result = new Result();

		$isAfterOperation = $this->getItemBeforeSave() !== null;
		if (!$isAfterOperation)
		{
			$result->addError(
				new Error('Action can only be executing after saving')
			);
			return $result;
		}

		$productRows = $item->getProductRows();
		if ($productRows instanceof ProductRowCollection)
		{
			$this->fillReservationResult($productRows);
			$this->synchronizeReserves($item->getId());
		}

		return $result;
	}

	protected function fillReservationResultRow(int $rowId, ProductRowReservation $productReservation): void
	{
		$actualValues = $productReservation->collectValues(Values::ACTUAL);
		if (empty($actualValues))
		{
			return;
		}

		$storeId = (int)$actualValues['STORE_ID'];
		$dateReserveEnd = $actualValues['DATE_RESERVE_END'];
		$reserveQuantity = (float)$actualValues['RESERVE_QUANTITY'];

		$reserveInfo = $this->reservationResult->addReserveInfo(
			$rowId,
			$reserveQuantity,
			0
		);
		$reserveInfo->setStoreId($storeId);
		$reserveInfo->setDateReserveEnd($dateReserveEnd);

		$reserveInfo->setDeltaReserveQuantity($reserveQuantity);
		$reserveInfo->setChanged(true);
	}
}

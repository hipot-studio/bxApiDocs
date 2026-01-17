<?php

namespace Bitrix\Crm\Reservation\Actions;

use Bitrix\Crm\Item;
use Bitrix\Crm\ProductRowCollection;
use Bitrix\Crm\Reservation\Tools\DateTimeComparator;
use Bitrix\Crm\Reservation\ProductRowReservation;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Objectify\Values;
use Bitrix\Main\Result;

/**
 * Synchronize reserves on update deal.
 *
 * It is processed in two stages: before and after saving, and you need to use the same instance.
 * Example:
 * ```php
	public function getUpdateOperation(Item $item, Context $context = null): Operation\Update
	{
		$operation = parent::getUpdateOperation($item, $context);

		$synchronizeReserveOperation = new SynchronizeReservesOnUpdate();

		$operation
			->addAction(
				Operation::ACTION_BEFORE_SAVE,
				$synchronizeReserveOperation
			)
			->addAction(
				Operation::ACTION_AFTER_SAVE,
				$synchronizeReserveOperation
			)
		;

		// ...
	}
 * ```
 */
class SynchronizeReservesOnUpdate extends SynchronizeReserves
{
	private bool $isFirstRun = true;

	/**
	 * @inheritDoc
	 */
	public function process(Item $item): Result
	{
		$result = new Result();

		$isAfterOperation = $this->getItemBeforeSave() !== null;
		if ($this->isFirstRun && $isAfterOperation)
		{
			$result->addError(
				new Error('Action can only be executing on both steps at once (before and after saving)')
			);
			return $result;
		}

		// runs and before, and after for processing new product rows (by before step the rows not contains ID)
		$productRows = $item->getProductRows();
		if ($productRows instanceof ProductRowCollection)
		{
			$this->fillReservationResult($productRows);
		}

		if ($this->isFirstRun)
		{
			$this->isFirstRun = false;
		}
		else
		{
			$this->synchronizeReserves($item->getId());
		}

		return $result;
	}

	protected function fillReservationResultRow(int $rowId, ProductRowReservation $productReservation): void
	{
		$actualValues = $productReservation->collectValues(Values::ACTUAL);
		$newValues = $productReservation->collectValues(Values::CURRENT);
		if (empty($newValues) && empty($actualValues))
		{
			return;
		}

		$isNotSavedOnSale = $productReservation->getReserveId() === null;

		if (empty($newValues))
		{
			$actualReserveQuantity = (float)$actualValues['RESERVE_QUANTITY'];

			$reserveInfo = $this->reservationResult->addReserveInfo(
				$rowId,
				$actualReserveQuantity,
				0
			);
			$reserveInfo->setStoreId((int)$actualValues['STORE_ID'] ?: null);
			$reserveInfo->setDateReserveEnd($actualValues['DATE_RESERVE_END']);

			if ($isNotSavedOnSale)
			{
				$reserveInfo->setDeltaReserveQuantity($actualReserveQuantity);
				$reserveInfo->setChanged();
			}

			return;
		}

		$newStoreId = $productReservation->getStoreId();
		$newReserveQuantity = $productReservation->getReserveQuantity();
		$newDateReserveEnd = $productReservation->getDateReserveEnd();

		$actualStoreId = (int)($actualValues['STORE_ID'] ?? 0);
		$actualReserveQuantity = (float)($actualValues['RESERVE_QUANTITY'] ?? 0);
		$actualDateReserveEnd = $actualValues['DATE_RESERVE_END'] ?? null;

		$reserveInfo = $this->reservationResult->addReserveInfo(
			$rowId,
			$newReserveQuantity,
			$newReserveQuantity - $actualReserveQuantity
		);
		$reserveInfo->setStoreId($newStoreId ?: null);
		$reserveInfo->setDateReserveEnd($newDateReserveEnd);

		$isStoreChanged = $newStoreId !== $actualStoreId;
		if ($isStoreChanged)
		{
			$reserveInfo->setDeltaReserveQuantity($reserveInfo->getReserveQuantity());
		}

		if (
			$isNotSavedOnSale
			|| $isStoreChanged
			|| !DateTimeComparator::areEqual($newDateReserveEnd, $actualDateReserveEnd)
		)
		{
			$reserveInfo->setChanged();
		}
	}
}

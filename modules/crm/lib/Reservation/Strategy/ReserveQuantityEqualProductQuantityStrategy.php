<?php

namespace Bitrix\Crm\Reservation\Strategy;

use Bitrix\Crm\ProductRowTable;
use Bitrix\Crm\Reservation\Tools\DateTimeComparator;
use Bitrix\Crm\Reservation\Internals\ProductRowReservationTable;
use Bitrix\Crm\Reservation\Strategy\Reserve\ReservationResult;
use Bitrix\Crm\Service\Sale\Reservation\ReservationService;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use CCrmOwnerTypeAbbr;

/**
 * Automatic reservation of the quantity equal to the quantity of product in the deal.
 * As soon as the user manually changes product quantity, the automation is disabled.
 */
class ReserveQuantityEqualProductQuantityStrategy extends ReserveStrategy
{
	/**
	 * @inheritDoc
	 */
	public function reservation(int $entityTypeId, int $entityId): ReservationResult
	{
		$result = new ReservationResult();

		$rows = $this->getProductRows($entityTypeId, $entityId);
		foreach ($rows as $row)
		{
			$rowId = (int)$row['ID'];
			$quantity = (float)$row['QUANTITY'];
			if ($row['RESERVE_ID'])
			{
				$saveResult = new ReservationResult();
				$updateFields = [];
				if ($row['RESERVE_DATE_RESERVE_END'] === null)
				{
					$updateFields['DATE_RESERVE_END'] = $this->getDefaultDateReserveEnd();
				}
				$reserveInfo = $result->addReserveInfo(
					$rowId,
					$quantity,
					0
				);
				$reserveInfo->setStoreId($row['RESERVE_STORE_ID']);
				$reserveInfo->setDateReserveEnd(
					$row['RESERVE_DATE_RESERVE_END'] ?? $this->getDefaultDateReserveEnd()
				);

				if ($row['RESERVE_IS_AUTO'] === 'Y')
				{
					$reserveQuantity = (float)$row['RESERVE_QUANTITY'];
					if ($reserveQuantity !== $quantity)
					{
						$updateFields['RESERVE_QUANTITY'] = $quantity;
						$reserveInfo->setDeltaReserveQuantity($quantity - $reserveQuantity);
					}
				}

				if (!empty($updateFields))
				{
					$updateFields['ID'] = $row['RESERVE_ID'];
					$saveResult = $this->saveCrmReserve($updateFields);
				}
			}
			else
			{
				$saveResult = $this->saveCrmReserve([
					'ROW_ID' => $rowId,
					'RESERVE_QUANTITY' => $quantity,
					'STORE_ID' => $this->getDefaultStoreId(),
					'DATE_RESERVE_END' => $this->getDefaultDateReserveEnd(),
					'IS_AUTO' => 'Y',
				]);

				$reserveInfo = $result->addReserveInfo(
					$rowId,
					$quantity,
					$quantity
				);
				$reserveInfo->setStoreId($this->getDefaultStoreId());
				$reserveInfo->setDateReserveEnd($this->getDefaultDateReserveEnd());
			}

			$result->addErrors($saveResult->getErrors());
		}

		return $result;
	}

	/**
	 * @inheritDoc
	 */
	public function reservationProductRow(int $productRowId, float $quantity, int $storeId, DateTime $dateReserveEnd): ReservationResult
	{
		$result = new ReservationResult();

		$productRow = $this->getProductRow($productRowId);
		if (!$productRow)
		{
			$result->addError(
				new Error(Loc::getMessage('CRM_RESERVATION_STRATEGY_RESERVE_QUANTITY_EQUAL_PRODUCT_QUANTITY_STRATEDY_PRODUCT_NOT_FOUND'))
			);

			return $result;
		}

		if (
			ReservationService::getInstance()->isRestrictedType((int)$productRow['TYPE'])
			|| (int)$productRow['PRODUCT_ID'] === 0
		)
		{
			$result->addError(
				new Error(Loc::getMessage('CRM_RESERVATION_STRATEGY_RESERVE_QUANTITY_EQUAL_PRODUCT_QUANTITY_STRATEGY_PRODUCT_NOT_SUPPORT_RESERVATION'))
			);

			return $result;
		}

		$productRowQuantity = (float)$productRow['QUANTITY'];
		if ($productRowQuantity < $quantity)
		{
			$result->addError(
				new Error(Loc::getMessage('CRM_RESERVATION_STRATEGY_RESERVE_QUANTITY_EQUAL_PRODUCT_QUANTITY_STRATEDY_LARGE_RESERVE_THAN_QUANTITY'))
			);
			return $result;
		}

		$reserveInfo = $result->addReserveInfo(
			$productRowId,
			$quantity,
			$quantity
		);
		$reserveInfo->setStoreId($storeId);
		$reserveInfo->setDateReserveEnd($dateReserveEnd);

		$isAutoReservation = $productRowQuantity === $quantity;
		$existReserve = $this->getReserve($productRowId);
		if ($existReserve)
		{
			if (!DateTimeComparator::areEqual($existReserve['DATE_RESERVE_END'], $dateReserveEnd))
			{
				$reserveInfo->setChanged();
			}

			if ((int)$existReserve['STORE_ID'] !== $storeId)
			{
				$reserveInfo->setChanged();
			}
			else
			{
				$reserveInfo->setDeltaReserveQuantity($quantity - (float)$existReserve['RESERVE_QUANTITY']);
			}

			$saveResult = $this->saveCrmReserve([
				'ID' => $existReserve['ID'],
				'RESERVE_QUANTITY' => $quantity,
				'STORE_ID' => $storeId,
				'DATE_RESERVE_END' => $dateReserveEnd,
				'IS_AUTO' => $isAutoReservation && $existReserve['IS_AUTO'] === 'Y' ? 'Y' : 'N',
			]);

			$result->addErrors(
				$saveResult->getErrors()
			);

			return $result;
		}

		// If the quantity is empty, reserve all. Only when adding.
		if ($quantity === 0.0)
		{
			$quantity = $productRowQuantity;
			$isAutoReservation = true;

			$reserveInfo->setReserveQuantity($quantity);
			$reserveInfo->setDeltaReserveQuantity($quantity);
		}

		$saveResult = $this->saveCrmReserve([
			'ROW_ID' => $productRowId,
			'RESERVE_QUANTITY' => $quantity,
			'STORE_ID' => $storeId,
			'DATE_RESERVE_END' => $dateReserveEnd,
			'IS_AUTO' => $isAutoReservation ? 'Y' : 'N',
		]);

		$result->addErrors(
			$saveResult->getErrors()
		);

		return $result;
	}

	/**
	 * Get products rows.
	 *
	 * @param int $ownerTypeId
	 * @param int $ownerId
	 *
	 * @return array
	 */
	protected function getProductRows(int $ownerTypeId, int $ownerId): array
	{
		return ProductRowTable::getList([
			'select' => [
				'ID',
				'QUANTITY',
				'RESERVE_ID' => ProductRowReservationTable::PRODUCT_ROW_RESERVATION_NAME . '.ID',
				'RESERVE_QUANTITY' => ProductRowReservationTable::PRODUCT_ROW_RESERVATION_NAME . '.RESERVE_QUANTITY',
				'RESERVE_IS_AUTO' => ProductRowReservationTable::PRODUCT_ROW_RESERVATION_NAME . '.IS_AUTO',
				'RESERVE_DATE_RESERVE_END' => ProductRowReservationTable::PRODUCT_ROW_RESERVATION_NAME . '.DATE_RESERVE_END',
				'RESERVE_STORE_ID' => ProductRowReservationTable::PRODUCT_ROW_RESERVATION_NAME . '.STORE_ID',
			],
			'filter' => [
				'=OWNER_TYPE' => CCrmOwnerTypeAbbr::ResolveByTypeID($ownerTypeId),
				'=OWNER_ID' => $ownerId,
				'!@TYPE' => ReservationService::getInstance()->getRestrictedProductTypes(),
				'!=PRODUCT_ID' => 0,
			],
		])->fetchAll();
	}
}

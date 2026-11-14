<?php

namespace Bitrix\Crm\Service\Sale\Reservation;

use Bitrix\Crm\Reservation\Internals\ProductRowReservationTable;
use Bitrix\Crm\Reservation\ProductRowReservation;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Sale\BasketService;
use Bitrix\Crm\Service\Sale\Shipment\ProductService;
use CCrmOwnerTypeAbbr;

/**
 * Finds active reserves of product rows on a store.
 *
 * A reserve is active while its unshipped part (reserve quantity minus the quantity of the row in the deducted
 * shipments of its owner) is above zero, whatever its end date. A reserve of a row of a closed deal is never active.
 * A reserve whose row has no owner or no basket item is active.
 */
final class StoreActiveReserveChecker
{
	public const BATCH_SIZE = 500;

	private BasketService $basketService;
	private ProductService $shipmentProductService;

	public function __construct(
		?BasketService $basketService = null,
		?ProductService $shipmentProductService = null
	)
	{
		$this->basketService = $basketService ?? BasketService::getInstance();
		$this->shipmentProductService =
			$shipmentProductService
			?? Container::getInstance()->getShipmentProductService()
		;
	}

	public function hasActiveReserve(int $storeId): bool
	{
		if ($storeId <= 0)
		{
			return false;
		}

		$lastId = 0;
		do
		{
			$candidates = $this->getCandidates($storeId, $lastId);
			if (empty($candidates))
			{
				return false;
			}

			if ($this->hasActiveCandidate($candidates))
			{
				return true;
			}

			$lastId = (int)end($candidates)['ID'];
		}
		while (count($candidates) === self::BATCH_SIZE);

		return false;
	}

	private function getCandidates(int $storeId, int $lastId): array
	{
		$productRow = ProductRowReservation::PRODUCT_ROW_NAME;

		return ProductRowReservationTable::getList([
			'select' => [
				'ID',
				'ROW_ID',
				'RESERVE_QUANTITY',
				'OWNER_TYPE' => $productRow . '.OWNER_TYPE',
				'OWNER_ID' => $productRow . '.OWNER_ID',
				'DEAL_CLOSED' => $productRow . '.DEAL_OWNER.CLOSED',
			],
			'filter' => [
				'=STORE_ID' => $storeId,
				'>RESERVE_QUANTITY' => 0,
				'>ID' => $lastId,
			],
			'order' => [
				'ID' => 'ASC',
			],
			'limit' => self::BATCH_SIZE,
		])->fetchAll();
	}

	private function hasActiveCandidate(array $candidates): bool
	{
		$reserveQuantities = [];
		$ownerIdsByType = [];
		foreach ($candidates as $candidate)
		{
			$ownerType = (string)($candidate['OWNER_TYPE'] ?? '');
			if ($ownerType === CCrmOwnerTypeAbbr::Deal && $this->isClosed($candidate['DEAL_CLOSED'] ?? null))
			{
				continue;
			}

			$ownerTypeId = $ownerType === '' ? 0 : (int)CCrmOwnerTypeAbbr::ResolveTypeID($ownerType);
			$ownerId = (int)($candidate['OWNER_ID'] ?? 0);
			if ($ownerTypeId <= 0 || $ownerId <= 0)
			{
				return true;
			}

			$ownerIdsByType[$ownerTypeId][$ownerId] = $ownerId;
			$rowId = (int)$candidate['ROW_ID'];
			$reserveQuantities[$rowId] = ($reserveQuantities[$rowId] ?? 0.0) + (float)$candidate['RESERVE_QUANTITY'];
		}

		if (empty($reserveQuantities))
		{
			return false;
		}

		$rowToBasket = [];
		foreach ($ownerIdsByType as $ownerTypeId => $ownerIds)
		{
			$relations = $this->basketService->getRowIdsToBasketIdsByEntities($ownerTypeId, array_values($ownerIds));
			foreach ($relations as $ownerRelations)
			{
				$rowToBasket += $ownerRelations;
			}
		}

		$candidateRowToBasket = [];
		foreach (array_keys($reserveQuantities) as $rowId)
		{
			if (!isset($rowToBasket[$rowId]))
			{
				return true;
			}

			$candidateRowToBasket[$rowId] = $rowToBasket[$rowId];
		}

		$shippedQuantities = $this->shipmentProductService->getShippedQuantityByRowBasketMap($candidateRowToBasket);
		foreach ($reserveQuantities as $rowId => $reserveQuantity)
		{
			if ($reserveQuantity - ($shippedQuantities[$rowId] ?? 0.0) > 0.0)
			{
				return true;
			}
		}

		return false;
	}

	private function isClosed(mixed $value): bool
	{
		return $value === 'Y' || $value === true;
	}
}

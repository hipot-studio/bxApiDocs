<?php

namespace Bitrix\Crm\Service\Sale;

use Bitrix\Crm\Binding\OrderEntityTable;
use Bitrix\Crm\ProductRowTable;
use Bitrix\Crm\Service\Sale\Basket\ProductRelationsBuilder;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Sale\Internals\BasketTable;
use CCrmOwnerTypeAbbr;

/**
 * Service for working with basket items.
 */
class BasketService
{
	public function __construct()
	{
		Loader::includeModule('sale');
	}

	/**
	 * Service instance.
	 *
	 * @return self
	 */
	public static function getInstance(): self
	{
		return ServiceLocator::getInstance()->get('crm.basket');
	}

	/**
	 * Get relations sale basket items and crm product rows.
	 *
	 * @param array $filter for tablet `ProductRowTable`.
	 *
	 * @return array in format `['rowId' => 'basketId']`
	 */
	private function getRowsIdsToBasketIdsByFilter(array $filter): array
	{
		$rows = ProductRowTable::getList([
			'select' => [
				'ID',
				'OWNER_ID',
				'OWNER_TYPE',
				'PRODUCT_ID',
				'PRICE',
				'QUANTITY',
				'XML_ID',
			],
			'filter' => $filter,
			'order' => [
				'ID' => 'ASC',
			],
		]);
		if ($rows->getSelectedRowsCount() === 0)
		{
			return [];
		}

		$productRelations = new ProductRelationsBuilder();
		$orderFilters = [];

		foreach ($rows as $row)
		{
			$productRelations->addCrmProductRow(
				(int)$row['ID'],
				(int)$row['PRODUCT_ID'],
				(float)$row['PRICE'],
				(float)$row['QUANTITY'],
				(string)$row['XML_ID']
			);

			$key = $row['OWNER_TYPE'] . $row['OWNER_ID'];
			if (empty($orderFilters[$key]))
			{
				$orderFilters[$key] = [
					'=OWNER_TYPE_ID' => CCrmOwnerTypeAbbr::ResolveTypeID($row['OWNER_TYPE']),
					'=OWNER_ID' => (int)$row['OWNER_ID'],
				];
			}
		}

		if (empty($orderFilters))
		{
			return [];
		}
		$rows = OrderEntityTable::getList([
			'select' => [
				'ORDER_ID',
			],
			'filter' => [
				'LOGIC' => 'OR',
				...array_values($orderFilters),
			],
		]);

		$orderIds = array_column($rows->fetchAll(), 'ORDER_ID');
		if (empty($orderIds))
		{
			return [];
		}

		$rows = BasketTable::getList([
			'select' => [
				'ID',
				'PRODUCT_ID',
				'QUANTITY',
				'PRICE',
				'XML_ID',
			],
			'filter' => [
				'=ORDER_ID' => $orderIds,
			],
			'order' => [
				'ID' => 'ASC',
			],
		]);
		if ($rows->getSelectedRowsCount() === 0)
		{
			return [];
		}

		foreach ($rows as $row)
		{
			$productRelations->addSaleBasketItem(
				(int)$row['ID'],
				(int)$row['PRODUCT_ID'],
				(float)$row['PRICE'],
				(float)$row['QUANTITY'],
				(string)$row['XML_ID']
			);
		}
		return $productRelations->getRelations();
	}

	/**
	 * Get relations sale basket items and crm product rows.
	 *
	 * @param int $ownerTypeId
	 * @param int $ownerId
	 *
	 * @return array in format `['rowId' => 'basketId']`
	 */
	public function getRowIdsToBasketIdsByEntity(int $ownerTypeId, int $ownerId): array
	{
		return $this->getRowsIdsToBasketIdsByFilter([
			'=OWNER_TYPE' => CCrmOwnerTypeAbbr::ResolveByTypeID($ownerTypeId),
			'=OWNER_ID' => $ownerId,
		]);
	}

	/**
	 * Get relations sale basket items and crm product rows of several owners of one type.
	 *
	 * Each owner is matched only with the basket items of its own orders, as `getRowIdsToBasketIdsByEntity` does.
	 *
	 * @param int $ownerTypeId
	 * @param int[] $ownerIds
	 *
	 * @return array in format `[ownerId => [rowId => basketId]]`; every requested owner is a key,
	 * an owner without rows, orders or relations has an empty array.
	 */
	public function getRowIdsToBasketIdsByEntities(int $ownerTypeId, array $ownerIds): array
	{
		$result = [];
		foreach ($ownerIds as $ownerId)
		{
			$result[(int)$ownerId] = [];
		}

		$ownerIds = array_filter(array_keys($result), static fn(int $ownerId) => $ownerId > 0);
		if (empty($ownerIds))
		{
			return $result;
		}

		$rowsByOwner = [];
		$rows = ProductRowTable::getList([
			'select' => [
				'ID',
				'OWNER_ID',
				'PRODUCT_ID',
				'PRICE',
				'QUANTITY',
				'XML_ID',
			],
			'filter' => [
				'=OWNER_TYPE' => CCrmOwnerTypeAbbr::ResolveByTypeID($ownerTypeId),
				'@OWNER_ID' => $ownerIds,
			],
			'order' => [
				'ID' => 'ASC',
			],
		]);
		foreach ($rows as $row)
		{
			$rowsByOwner[(int)$row['OWNER_ID']][] = $row;
		}
		if (empty($rowsByOwner))
		{
			return $result;
		}

		$ownersByOrder = [];
		$rows = OrderEntityTable::getList([
			'select' => [
				'ORDER_ID',
				'OWNER_ID',
			],
			'filter' => [
				'=OWNER_TYPE_ID' => $ownerTypeId,
				'@OWNER_ID' => array_keys($rowsByOwner),
			],
		]);
		foreach ($rows as $row)
		{
			$ownersByOrder[(int)$row['ORDER_ID']][] = (int)$row['OWNER_ID'];
		}
		if (empty($ownersByOrder))
		{
			return $result;
		}

		$basketItemsByOwner = [];
		$rows = BasketTable::getList([
			'select' => [
				'ID',
				'ORDER_ID',
				'PRODUCT_ID',
				'QUANTITY',
				'PRICE',
				'XML_ID',
			],
			'filter' => [
				'@ORDER_ID' => array_keys($ownersByOrder),
			],
			'order' => [
				'ID' => 'ASC',
			],
		]);
		foreach ($rows as $row)
		{
			foreach ($ownersByOrder[(int)$row['ORDER_ID']] ?? [] as $ownerId)
			{
				$basketItemsByOwner[$ownerId][] = $row;
			}
		}

		foreach ($basketItemsByOwner as $ownerId => $basketItems)
		{
			$productRelations = new ProductRelationsBuilder();
			foreach ($rowsByOwner[$ownerId] ?? [] as $row)
			{
				$productRelations->addCrmProductRow(
					(int)$row['ID'],
					(int)$row['PRODUCT_ID'],
					(float)$row['PRICE'],
					(float)$row['QUANTITY'],
					(string)$row['XML_ID']
				);
			}
			foreach ($basketItems as $row)
			{
				$productRelations->addSaleBasketItem(
					(int)$row['ID'],
					(int)$row['PRODUCT_ID'],
					(float)$row['PRICE'],
					(float)$row['QUANTITY'],
					(string)$row['XML_ID']
				);
			}

			$result[$ownerId] = $productRelations->getRelations();
		}

		return $result;
	}

	/**
	 * Get relations sale basket items and crm product rows.
	 *
	 * @param array $rowsIds
	 *
	 * @return array in format `['rowId' => 'basketId']`
	 */
	public function getRowIdsToBasketIdsByRows(array $rowsIds): array
	{
		return $this->getRowsIdsToBasketIdsByFilter([
			'=ID' => $rowsIds,
		]);
	}
}

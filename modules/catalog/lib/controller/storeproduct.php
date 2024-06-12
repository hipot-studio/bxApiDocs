<?php

namespace Bitrix\Catalog\Controller;

use Bitrix\Catalog\Access\ActionDictionary;
use Bitrix\Catalog\Config\State;
use Bitrix\Catalog\ProductTable;
use Bitrix\Catalog\StoreProductTable;
use Bitrix\Catalog\StoreTable;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\Response\DataType\Page;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Catalog\Store\EnableWizard\Manager;

final class StoreProduct extends Controller
{
	protected const ITEM = 'STORE_PRODUCT';
	protected const LIST = 'STORE_PRODUCTS';

	private const BULK_SAVE_CHUNK_SIZE = 10000;
	private const BULK_SAVE_PRODUCTS_CHUNK_SIZE = 1000;

	//region Actions
	public function getFieldsAction(): array
	{
		return [self::ITEM => $this->getViewFields()];
	}

	public function listAction(PageNavigation $pageNavigation, array $select = [], array $filter = [], array $order = []): Page
	{
		$accessFilter = $this->accessController->getEntityFilter(
			ActionDictionary::ACTION_STORE_VIEW,
			get_class($this->getEntityTable())
		);
		if ($accessFilter)
		{
			$filter = [
				$accessFilter,
				$filter,
			];
		}

		return new Page(
			self::LIST,
			$this->getList($select, $filter, $order, $pageNavigation),
			$this->count($filter)
		);
	}

	public function getAction($id)
	{
		$r = $this->exists($id);
		if($r->isSuccess())
		{
			return [self::ITEM => $this->get($id)];
		}
		else
		{
			$this->addErrors($r->getErrors());
			return null;
		}
	}

	public function bulkSaveAction(array $items)
	{
		if (!State::isUsedInventoryManagement())
		{
			$this->addError(
				new Error(
					'Inventory management is not enabled',
					200040400010
				)
			);

			return null;
		}

		if (!Manager::isOnecMode())
		{
			$this->addError(
				new Error(
					'Inventory management is not in 1C mode',
					200040400020
				)
			);

			return null;
		}

		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();

		$storeIds = array_flip(
			array_map(
				static fn($row) => (int)$row['ID'],
				StoreTable::getList([
					'select' => ['ID'],
					'cache' => ['ttl' => 86400],
				])->fetchAll()
			)
		);

		foreach (array_chunk($items, self::BULK_SAVE_CHUNK_SIZE) as $chunkItems)
		{
			$chunkProductIds = array_unique(
				array_map(
					\Closure::fromCallable('intval'),
					array_column($chunkItems, 'productId')
				)
			);
			$productIds = [];
			if ($chunkProductIds)
			{
				$productIds = array_flip(
					array_map(
						static fn($row) => (int)$row['ID'],
						ProductTable::query()
							->setSelect(['ID'])
							->whereIn('ID', $chunkProductIds)
							->fetchAll()
					)
				);
			}

			$insertRows = [];
			foreach ($chunkItems as $item)
			{
				$productId = isset($item['productId']) ? (int)$item['productId'] : 0;
				if (!isset($productIds[$productId]))
				{
					continue;
				}

				$storeId = isset($item['storeId']) ? (int)$item['storeId'] : 0;
				if (!isset($storeIds[$storeId]))
				{
					continue;
				}

				$amount = isset($item['value']['amount']) ? (float)$item['value']['amount'] : 0;
				$quantityReserved = isset($item['value']['quantityReserved'])
					? (float)$item['value']['quantityReserved']
					: 0
				;

				$insertRows[] = [
					'PRODUCT_ID' => $productId,
					'STORE_ID' => $storeId,
					'AMOUNT' => $amount,
					'QUANTITY_RESERVED' => $quantityReserved,
				];
			}

			if (!$insertRows)
			{
				continue;
			}

			$sqls = $sqlHelper->prepareMergeMultiple(
				StoreProductTable::getTableName(),
				[
					'PRODUCT_ID',
					'STORE_ID',
				],
				$insertRows
			);
			foreach ($sqls as $sql)
			{
				$connection->query($sql);
			}
		}

		$allProductIds = array_unique(
			array_map(
				\Closure::fromCallable('intval'),
				array_column($items, 'productId')
			)
		);
		foreach (array_chunk($allProductIds, self::BULK_SAVE_PRODUCTS_CHUNK_SIZE) as $chunkProductIds)
		{
			Application::getInstance()->addBackgroundJob(function() use ($chunkProductIds) {
				\CCatalogStore::recalculateProductsBalances($chunkProductIds);
			});
		}

		return true;
	}

	//endregion

	protected function exists($id)
	{
		$r = new Result();
		if(isset($this->get($id)['ID']) == false)
			$r->addError(new Error('Entity is not exists'));

		return $r;
	}

	protected function getEntityTable()
	{
		return new StoreProductTable();
	}

	protected function checkReadPermissionEntity()
	{
		$r = new Result();

		if (!(
			$this->accessController->check(ActionDictionary::ACTION_CATALOG_READ)
			|| $this->accessController->check(ActionDictionary::ACTION_STORE_VIEW)
		))
		{
			$r->addError(new Error('Access Denied', 200040300010));
		}
		return $r;
	}

	protected function checkPermissionEntity($name, $arguments = [])
	{
		$name = mb_strtolower($name); //for ajax mode

		if ($name == 'bulksave')
		{
			return $this->checkReadPermissionEntity();
		}
		else
		{
			$r = parent::checkPermissionEntity($name);
		}

		return $r;
	}
}

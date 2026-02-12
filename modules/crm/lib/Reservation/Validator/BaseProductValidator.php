<?php

declare(strict_types=1);

namespace Bitrix\Crm\Reservation\Validator;

use Bitrix\Crm\ProductRow;
use Bitrix\Crm\ProductRowCollection;
use Bitrix\Crm\Service\Sale\Reservation\ReservationService;
use Bitrix\Main\Type\Dictionary;

abstract class BaseProductValidator extends BaseValidator
{
	private const PRODUCT_IDS_KEY = 'PRODUCT_IDS';
	private const PRODUCT_NAMES_KEY = 'PRODUCT_NAMES';

	protected Dictionary $dictionary;
	protected ReservationService $service;

	public function __construct()
	{
		parent::__construct();
		$this->dictionary = new Dictionary();
		$this->service = ReservationService::getInstance();
	}

	public function __destruct()
	{
		unset(
			$this->dictionary,
			$this->service,
		);
		parent::__destruct();
	}

	protected function getProductIds(): array
	{
		$result = $this->dictionary->get(self::PRODUCT_IDS_KEY);

		return is_array($result) ? $result : [];
	}

	protected function setProductIds(array $productIds): static
	{
		$this->dictionary->set(self::PRODUCT_IDS_KEY, $productIds);

		return $this;
	}

	protected function addProductId(int $productId): static
	{
		$values = $this->getProductIds();
		$values[] = $productId;

		return $this->setProductIds(array_unique($values));
	}

	protected function getProductNames(): array
	{
		$result = $this->dictionary->get(self::PRODUCT_NAMES_KEY);

		return is_array($result) ? $result : [];
	}

	protected function setProductNames(array $productNames): static
	{
		$this->dictionary->set(self::PRODUCT_NAMES_KEY, $productNames);

		return $this;
	}

	protected function addProductName(int $productId, string $productName): static
	{
		$values = $this->getProductNames();
		$values[$productId] = $productName;

		return $this->setProductNames($values);
	}

	protected function getProductNameByIds(array $productIds): array
	{
		if (empty($productIds))
		{
			return [];
		}
		$productIds = array_fill_keys($productIds, true);

		return array_intersect_key($this->getProductNames(), $productIds);
	}

	protected function fillProductsFromCollection(ProductRowCollection $collection): static
	{
		$this->dictionary->clear();

		/** @var ProductRow $productRow */
		foreach ($collection as $productRow)
		{
			if ($this->isNeedValidateProductRow($productRow))
			{
				$this->fillDictionaryFromProductRow($productRow);
			}
		}
		unset(
			$productReservation,
			$productRow,
		);

		return $this;
	}

	protected function fillDictionaryFromProductRow(ProductRow $productRow): void
	{
		$productId = $productRow->getProductId();
		$this->addProductId($productId);
		$this->addProductName($productId, $productRow->getProductName());
	}

	protected function fillProductsFromRows(array $currentRows, array $actualRows): static
	{
		$this->dictionary->clear();

		foreach (array_keys($currentRows) as $rowId)
		{
			$row = $currentRows[$rowId];
			if ($this->isNeedValidateProduct($row, $actualRows[$rowId] ?? null))
			{
				$this->fillDictionaryFromProduct($row);
			}
		}

		return $this;
	}

	protected function fillDictionaryFromProduct(array $product): void
	{
		$productId = (int)$product['PRODUCT_ID'];
		$this->addProductId($productId);
		$this->addProductName($productId, $product['PRODUCT_NAME']);
	}

	abstract protected function isNeedValidateProductRow(ProductRow $productRow): bool;

	abstract protected function isNeedValidateProduct(array $currentRow, ?array $actualRow): bool;
}

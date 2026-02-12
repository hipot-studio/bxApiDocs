<?php

declare(strict_types=1);

namespace Bitrix\Crm\Reservation\Validator;

use Bitrix\Catalog\ProductTable;
use Bitrix\Crm\ProductRow;
use Bitrix\Crm\ProductRowCollection;
use Bitrix\Crm\Reservation\Error\BaseProductErrorAssembler;
use Bitrix\Crm\Reservation\ProductRowReservation;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

class AvailableProduct extends BaseProductValidator
{
	public const ERROR_CATALOG_IS_ABSENT = 'CATALOG_IS_ABSENT';
	public const ERROR_AVAILABLE_PRODUCT = 'ERROR_AVAILABLE_PRODUCT';

	private const PRODUCT_RESERVES_KEY = 'PRODUCT_RESERVE';

	protected function initErrorAssembler(): void
	{
		parent::initErrorAssembler();

		$assembler = $this->getErrorAssembler();
		$assembler->setErrorCode(static::ERROR_AVAILABLE_PRODUCT);
		$assembler->setErrorTemplates([
			BaseProductErrorAssembler::TEMPLATE_PRODUCT_ONE => Loc::getMessage('CRM_RESERVATION_ERROR_AVAILABLE_PRODUCT_ERROR_ONE_PRODUCT'),
			BaseProductErrorAssembler::TEMPLATE_PRODUCT_MULTI => Loc::getMessage('CRM_RESERVATION_ERROR_AVAILABLE_PRODUCT_ERROR_MULTI_PRODUCTS'),
			BaseProductErrorAssembler::TEMPLATE_PRODUCT_TOO_MANY => Loc::getMessage('CRM_RESERVATION_ERROR_AVAILABLE_PRODUCT_ERROR_TOO_MANY_PRODUCTS'),
		]);
		unset(
			$assembler,
		);
	}

	protected function getProductReserves(): array
	{
		$result = $this->dictionary->get(self::PRODUCT_RESERVES_KEY);

		return is_array($result) ? $result : [];
	}

	protected function setProductReserves(array $productReserves): static
	{
		$this->dictionary->set(self::PRODUCT_RESERVES_KEY, $productReserves);

		return $this;
	}

	protected function addProductReserve(int $productId, int|float $reserve): static
	{
		$values = $this->getProductReserves();
		$values[$productId] ??= 0;
		$values[$productId] += $reserve;

		return $this->setProductReserves(array_unique($values));
	}

	public function validateCollection(?ProductRowCollection $collection): Result
	{
		if ($collection === null)
		{
			return new Result();
		}

		return $this->fillProductsFromCollection($collection)->validateProducts();
	}

	public function validateRows(array $currentRows, array $actualRows): Result
	{
		if (empty($currentRows))
		{
			return new Result();
		}

		return $this->fillProductsFromRows($currentRows, $actualRows)->validateProducts();
	}

	protected function getReserveFromProductRow(ProductRow $productRow): float
	{
		$result = 0;
		/**
		 * @var ProductRowReservation $productReservation
		 */
		$productReservation = $productRow->getProductRowReservation();
		if ($productReservation)
		{
			$result = $productReservation->getReserveQuantity();
		}
		unset(
			$productReservation,
		);

		return $result;
	}

	protected function getReserveFromProduct(array $product): float
	{
		$result = 0;
		if (isset($product['INPUT_RESERVE_QUANTITY']))
		{
			$result = (float)$product['INPUT_RESERVE_QUANTITY'];
		}
		elseif (isset($product['RESERVE_QUANTITY']))
		{
			$result = (float)$product['RESERVE_QUANTITY'];
		}

		return $result;
	}

	protected function fillDictionaryFromProductRow(ProductRow $productRow): void
	{
		parent::fillDictionaryFromProductRow($productRow);

		$this->addProductReserve(
			$productRow->getProductId(),
			$this->getReserveFromProductRow($productRow)
		);
	}

	protected function fillDictionaryFromProduct(array $product): void
	{
		parent::fillDictionaryFromProduct($product);

		$this->addProductReserve(
			(int)$product['PRODUCT_ID'],
			$this->getReserveFromProduct($product)
		);
	}

	protected function isNeedValidateProductRow(ProductRow $productRow): bool
	{
		if (!$productRow->isProductIdChanged())
		{
			return false;
		}
		if ((int)$productRow->getProductId() <= 0)
		{
			return false;
		}
		if ($this->service->isRestrictedType($productRow->getType()))
		{
			return false;
		}

		return $this->getReserveFromProductRow($productRow) > 0;
	}

	protected function isNeedValidateProduct(array $currentRow, ?array $actualRow): bool
	{
		$currentProductId = (int)($currentRow['PRODUCT_ID'] ?? 0);
		if ($currentProductId <= 0)
		{
			return false;
		}
		$actualProductId = (int)($actualRow['PRODUCT_ID'] ?? 0);

		if ($currentProductId === $actualProductId)
		{
			return false;
		}

		$productType = (int)($currentRow['PRODUCT_TYPE'] ?? \Bitrix\Catalog\ProductTable::TYPE_PRODUCT);
		if ($this->service->isRestrictedType($productType))
		{
			return false;
		}

		return $this->getReserveFromProduct($currentRow) > 0;
	}

	protected function validateProducts(): Result
	{
		$result = new Result();

		$productIds = $this->getProductIds();
		if (empty($productIds))
		{
			return $result;
		}

		if (!Loader::includeModule('catalog'))
		{
			$result->addError(new Error(
				Loc::getMessage('CRM_RESERVATION_VALIDATOR_AVAILABLE_PRODUCT_ERROR_CATALOG_IS_ABSENT'),
				static::ERROR_CATALOG_IS_ABSENT
			));

			return $result;
		}

		$notAvailableProductIds = $this->getNotAvailableProductIds($productIds);
		if (empty($notAvailableProductIds))
		{
			return $result;
		}

		$assembler = $this->getErrorAssembler();
		$assembler->setProductNames($this->getProductNameByIds($notAvailableProductIds));
		$result->addError($assembler->getError());
		unset(
			$assembler,
		);

		return $result;
	}

	protected function getNotAvailableProductIds(array $productIds): array
	{
		$result = array_fill_keys($productIds, true);

		$reserves = $this->getProductReserves();

		foreach (array_chunk($productIds, 500) as $pageIds)
		{
			$iterator = ProductTable::getList([
				'select' => [
					'ID',
					'AVAILABLE',
					'QUANTITY',
					'QUANTITY_TRACE',
					'CAN_BUY_ZERO',
				],
				'filter' => [
					'@ID' => $pageIds,
				],
			]);
			while ($row = $iterator->fetch())
			{
				$productId = (int)$row['ID'];
				if ($this->isProductValid($row, $reserves[$productId] ?? 0))
				{
					unset($result[$productId]);
				}
			}
			unset(
				$row,
				$iterator,
			);
		}

		return empty($result) ? [] : array_keys($result);
	}

	protected function isProductValid(array $product, float $reserve): bool
	{
		if ($product['AVAILABLE'] !== 'Y')
		{
			return false;
		}

		return !(
			$product['QUANTITY_TRACE'] === ProductTable::STATUS_YES
			&& $product['CAN_BUY_ZERO'] === ProductTable::STATUS_NO
			&& (float)$product['QUANTITY'] < $reserve
		);
	}
}

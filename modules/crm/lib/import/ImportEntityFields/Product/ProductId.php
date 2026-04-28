<?php

namespace Bitrix\Crm\Import\ImportEntityFields\Product;

use Bitrix\Crm\Import\Contract\ImportEntityFieldInterface;
use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Main\Localization\Loc;
use CCrmProduct;

final class ProductId implements ImportEntityFieldInterface
{
	public function getId(): string
	{
		return 'PRODUCT_ID';
	}

	public function getCaption(): string
	{
		return Loc::getMessage('CRM_ITEM_IMPORT_FIELD_PRODUCT_ID');
	}

	public function isRequired(): bool
	{
		return false;
	}

	public function isReadonly(): bool
	{
		return false;
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		$columnIndex = $fieldBindings->getColumnIndexByFieldId($this->getId());
		if ($columnIndex === null)
		{
			return FieldProcessResult::skip();
		}

		$productIdOrName = $row[$columnIndex] ?? null;
		if (empty($productIdOrName))
		{
			return FieldProcessResult::skip();
		}

		$importItemFields['PRODUCT_ROWS'] ??= [];

		$product = CCrmProduct::GetByOriginID("CRM_PROD_{$productIdOrName}");
		if (is_array($product))
		{
			$importItemFields['PRODUCT_ROWS'][] = [
				'PRODUCT_ID' => $product['ID'],
				'QUANTITY' => $this->getProductQuantity($row, $fieldBindings),
				'PRICE' => $this->getProductPrice($row, $fieldBindings),
			];

			return FieldProcessResult::success();
		}

		$product = CCrmProduct::GetByName($productIdOrName);

		$importItemFields['PRODUCT_ROWS'][] = [
			'PRODUCT_NAME' => $productIdOrName,
			'PRODUCT_ID' => $product['ID'] ?? 0,
			'PRICE' => $this->getProductPrice($row, $fieldBindings),
			'QUANTITY' => $this->getProductQuantity($row, $fieldBindings),
		];

		return FieldProcessResult::success();
	}

	private function getProductPrice(array $row, FieldBindings $fieldBindings): ?float
	{
		$priceColumnIndex = $fieldBindings->getColumnIndexByFieldId((new ProductPrice())->getId());
		if ($priceColumnIndex === null)
		{
			return null;
		}

		$price = $row[$priceColumnIndex] ?? null;
		if (!is_numeric($price) || (float)$price < 0)
		{
			return null;
		}

		return (float)$price;
	}

	private function getProductQuantity(array $row, FieldBindings $fieldBindings): int
	{
		$quantityColumnIndex = $fieldBindings->getColumnIndexByFieldId((new ProductQuantity())->getId());
		if ($quantityColumnIndex === null)
		{
			return 1;
		}

		$quantity = $row[$quantityColumnIndex] ?? null;
		if (!is_numeric($quantity) || (int)$quantity <= 0)
		{
			return 1;
		}

		return (int)$quantity;
	}
}

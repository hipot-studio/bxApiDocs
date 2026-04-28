<?php

namespace Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Item;
use Bitrix\Crm\Service\Display\Options;
use CCrmProduct;

final class Products extends AbstractFactoryBasedField
{
	/** @see Options::$multipleFieldsDelimiter */
	private string $delimiter = ', ';

	public function getId(): string
	{
		return Item::FIELD_NAME_PRODUCTS;
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		$columnIndex = $fieldBindings->getColumnIndexByFieldId($this->getId());
		if ($columnIndex === null)
		{
			return FieldProcessResult::skip();
		}

		$possibleProducts = $row[$columnIndex] ?? null;
		if (empty($possibleProducts))
		{
			return FieldProcessResult::skip();
		}

		$products = explode($this->delimiter, $possibleProducts);
		if (empty($products))
		{
			return FieldProcessResult::skip();
		}

		foreach ($products as $product)
		{
			$existsProduct = CCrmProduct::GetByName($product);
			$existsProductId = $existsProduct['ID'] ?? null;
			if (is_numeric($existsProductId) && (int)$existsProductId > 0)
			{
				$importItemFields['PRODUCT_ROWS'][] = [
					'PRODUCT_ID' => (int)$existsProductId,
					'PRODUCT_NAME' => $existsProduct['NAME'],
					'PRICE' => $existsProduct['PRICE'],
					'QUANTITY' => 1,
				];

				continue;
			}

			$importItemFields['PRODUCT_ROWS'][] = [
				'PRODUCT_NAME' => $product,
			];
		}

		return FieldProcessResult::success();
	}
}

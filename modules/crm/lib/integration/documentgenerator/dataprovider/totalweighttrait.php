<?php

namespace Bitrix\Crm\Integration\DocumentGenerator\DataProvider;

use Bitrix\Crm\Integration\DocumentGenerator\Value\ProductMeasure;

trait TotalWeightTrait
{
	/**
	 * Calculates total product weight in grams.
	 * Light mode does not publish the product collection, so the total weight is unknown.
	 *
	 * @return float|null
	 */
	public function calculateTotalWeight(): ?float
	{
		if ($this->isLightMode())
		{
			return null;
		}

		$totalWeight = 0.0;
		foreach ($this->loadProducts() as $product)
		{
			$weight = $product->getPhysicalRawValue('WEIGHT');
			if ($weight === null)
			{
				return null;
			}

			$totalWeight += $weight * (float)$product->getRawValue('QUANTITY');
		}

		return $totalWeight;
	}

	/**
	 * Returns total product weight as a document value.
	 *
	 * @return ProductMeasure
	 */
	public function getTotalWeight(): ProductMeasure
	{
		return new ProductMeasure($this->calculateTotalWeight(), ProductMeasure::WEIGHT);
	}
}

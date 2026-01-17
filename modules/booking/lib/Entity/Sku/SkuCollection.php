<?php

declare(strict_types=1);

namespace Bitrix\Booking\Entity\Sku;

use Bitrix\Booking\Entity\BaseEntityCollection;

/**
 * @method Sku|null getFirstCollectionItem()
 * @method Sku[] getIterator()
 */
class SkuCollection extends BaseEntityCollection
{
	public function __construct(Sku ...$skus)
	{
		foreach ($skus as $sku)
		{
			$this->collectionItems[] = $sku;
		}
	}

	public static function createByIds(int ...$ids): static
	{
		$skuCollection = new static();
		foreach ($ids as $id)
		{
			$sku = static::createSku(['id' => $id]);
			$skuCollection->add($sku);
		}

		return $skuCollection;
	}

	public static function mapFromArray(array $props): static
	{
		$skuCollection = new static();
		foreach ($props as $skuProps)
		{
			$sku = static::createSku($skuProps);
			$skuCollection->add($sku);
		}

		return $skuCollection;
	}

	protected static function createSku(array $props): Sku
	{
		return Sku::mapFromArray($props);
	}

	public function diff(SkuCollection $collectionToCompare): static
	{
		$compareSkuIds = array_flip($collectionToCompare->getEntityIds());

		$filtered = [];
		foreach ($this->collectionItems as $sku)
		{
			if (isset($compareSkuIds[$sku->getId()]))
			{
				continue;
			}

			$filtered[] = $sku;
		}

		return new static(...$filtered);
	}
}

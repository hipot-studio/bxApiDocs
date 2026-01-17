<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service;

use Bitrix\Booking\Entity\Sku\SkuCollection;
use Bitrix\Booking\Internals\Integration\Catalog\ServiceSkuProvider;

class SkuService
{
	public function __construct(
		private readonly ServiceSkuProvider $serviceSkuProvider,
	)
	{
	}

	public function checkSkuExists(SkuCollection $skuCollection) : bool
	{
		if ($skuCollection->isEmpty())
		{
			return true;
		}

		$ids = $skuCollection->getEntityIds();
		$skus = $this->serviceSkuProvider->get($ids);

		return count($ids) === count($skus);
	}
}

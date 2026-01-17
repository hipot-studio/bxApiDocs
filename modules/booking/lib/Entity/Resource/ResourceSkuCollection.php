<?php

declare(strict_types=1);

namespace Bitrix\Booking\Entity\Resource;

use Bitrix\Booking\Entity\Sku\SkuCollection;

/**
 * @method ResourceSku|null getFirstCollectionItem()
 * @method ResourceSku[] getIterator()
 */
class ResourceSkuCollection extends SkuCollection
{
	protected static function createSku(array $props): ResourceSku
	{
		return ResourceSku::mapFromArray($props);
	}
}

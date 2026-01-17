<?php

declare(strict_types=1);

namespace Bitrix\Booking\Entity\Booking;

use Bitrix\Booking\Entity\Sku\SkuCollection;

/**
 * @method BookingSku|null getFirstCollectionItem()
 * @method BookingSku[] getIterator()
 */
class BookingSkuCollection extends SkuCollection
{
	protected static function createSku(array $props): BookingSku
	{
		return BookingSku::mapFromArray($props);
	}
}

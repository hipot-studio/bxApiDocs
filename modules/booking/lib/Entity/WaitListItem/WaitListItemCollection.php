<?php

declare(strict_types=1);

namespace Bitrix\Booking\Entity\WaitListItem;

use Bitrix\Booking\Entity\BaseEntityCollection;

/**
 * @method WaitListItem|null getFirstCollectionItem()
 * @method \ArrayIterator<WaitListItem> getIterator()
 */
class WaitListItemCollection extends BaseEntityCollection
{
	public function __construct(WaitListItem ...$waitListItems)
	{
		foreach ($waitListItems as $waitListItem)
		{
			$this->collectionItems[] = $waitListItem;
		}
	}

	public static function mapFromArray(array $props): self
	{
		$waitListItems = [];
		foreach ($props as $waitListItem)
		{
			$waitListItems[] = WaitListItem::mapFromArray($waitListItem);
		}

		return new WaitListItemCollection(...$waitListItems);
	}
}

<?php

namespace Bitrix\Bizproc\Internal\Entity\StorageItem;

use Bitrix\Bizproc\Internal\Entity\BaseEntityCollection;

/**
 * @method \Bitrix\Bizproc\Internal\Entity\StorageItem\StorageItem|null getFirstCollectionItem()
 * @method \ArrayIterator<StorageItem> getIterator()
 */
class StorageItemCollection extends BaseEntityCollection
{
	public function __construct(StorageItem ...$storageTypes)
	{
		array_push($this->collectionItems, ...$storageTypes);
	}
}

<?php

namespace Bitrix\Bizproc\Internal\Entity\StorageType;

use Bitrix\Bizproc\Internal\Entity\BaseEntityCollection;

/**
 * @method \Bitrix\Bizproc\Internal\Entity\StorageType\StorageType|null getFirstCollectionItem()
 * @method \ArrayIterator<StorageType> getIterator()
 */
class StorageTypeCollection extends BaseEntityCollection
{
	public function __construct(StorageType ...$storageTypes)
	{
		foreach ($storageTypes as $storageType)
		{
			$this->collectionItems[] = $storageType;
		}
	}
}

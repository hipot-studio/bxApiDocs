<?php

namespace Bitrix\Bizproc\Internal\Entity\StorageField;

use Bitrix\Bizproc\Internal\Entity\BaseEntityCollection;

/**
 * @method \Bitrix\Bizproc\Internal\Entity\StorageField\StorageField|null getFirstCollectionItem()
 * @method \ArrayIterator<StorageField> getIterator()
 */
class StorageFieldCollection extends BaseEntityCollection
{
	public function __construct(StorageField ...$storageFields)
	{
		array_push($this->collectionItems, ...$storageFields);
	}
}

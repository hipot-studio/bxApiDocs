<?php

namespace Bitrix\Bizproc\Public\Command\StorageItem;

use Bitrix\Main\Result;
use Bitrix\Bizproc\Internal\Entity\StorageItem\StorageItem;

class StorageItemResult extends Result
{
	public function __construct(private ?StorageItem $storageItem = null)
	{
		parent::__construct();
	}

	public function getStorageItem(): ?StorageItem
	{
		return $this->storageItem;
	}
}

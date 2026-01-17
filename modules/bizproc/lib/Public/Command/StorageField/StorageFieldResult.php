<?php

namespace Bitrix\Bizproc\Public\Command\StorageField;

use Bitrix\Main\Result;
use Bitrix\Bizproc\Internal\Entity\StorageField\StorageField;

class StorageFieldResult extends Result
{
	public function __construct(private ?StorageField $storageField = null)
	{
		parent::__construct();
	}

	public function getStorageField(): ?StorageField
	{
		return $this->storageField;
	}
}
